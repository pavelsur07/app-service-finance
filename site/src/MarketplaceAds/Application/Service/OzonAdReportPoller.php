<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Application\DTO\PollResult;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Один опрос Ozon Performance /statistics/list на компанию и перевод всех её
 * in-flight записей в актуальное Ozon-state. Ядро будущей cron-задачи
 * «shared polling»: вместо одного воркера на UUID — один HTTP-запрос на
 * компанию и обновление N строк батчем.
 *
 * Не скачивает отчёт: строки в state=OK остаются finalized_at=NULL, финальный
 * download + markFinalized делает step 4 (DownloadOzonAdReportMessage handler).
 *
 * Чистый сервис: без Request / Session / транзакций. Вызывающий
 * {@see \App\MarketplaceAds\Command\OzonPollReportsCommand} решает, как
 * итерировать компании и когда flush'ить UoW.
 */
final class OzonAdReportPoller
{
    /**
     * Backoff в секундах, индекс = poll_attempts (1-based, clamp по верхней границе).
     *
     * computeNextPollAt() вызывается с $attempts = $pollAttempts + 1, поэтому
     * индекс 1 — schedule первого опроса после создания записи. Индекс 0
     * умышленно отсутствует: момент создания строки — не ответственность
     * этого сервиса (сегодня это FetchOzonAdStatisticsHandler, в step 4 —
     * download-диспетчер). После 5-й попытки держим 10 минут — компромисс
     * между «не дёргать Ozon» и «не держать row в in-flight сутками».
     */
    private const BACKOFF_SCHEDULE_SECONDS = [
        1 => 30,
        2 => 60,
        3 => 120,
        4 => 300,
        5 => 600,
    ];

    /**
     * Строки старше этого возраста без финализации — force-ABANDONED. Ограничивает
     * lifetime «зомби-UUID», которые Ozon не отдаёт в листинге.
     */
    private const MAX_AGE_BEFORE_ABANDON_SECONDS = 3600;

    public function __construct(
        private readonly OzonAdClient $client,
        private readonly OzonAdPendingReportRepository $repo,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, \DateTimeImmutable $now): PollResult
    {
        $inFlight = $this->repo->findInFlightByCompany($companyId);
        if ([] === $inFlight) {
            return new PollResult(seen: 0, updated: 0, finalized: 0, errors: 0);
        }

        // Один HTTP-запрос. Если он упал — строки не трогаем, next_poll_at
        // не менялся, следующий тик cron'а их снова подхватит.
        try {
            $ozonMap = $this->client->listReportsForCompany($companyId);
        } catch (OzonPermanentApiException $e) {
            foreach ($inFlight as $row) {
                $this->repo->markFinalized(
                    $row->getCompanyId(),
                    $row->getOzonUuid(),
                    OzonAdPendingReportState::ERROR,
                    'Ozon Performance API permanently denied: '.$e->getMessage(),
                );
            }

            $this->logger->warning('Ozon Performance API permanently denied — all in-flight reports marked ERROR', [
                'companyId' => $companyId,
                'count' => count($inFlight),
                'error' => $e->getMessage(),
            ]);

            return new PollResult(
                seen: count($inFlight),
                updated: 0,
                finalized: count($inFlight),
                errors: count($inFlight),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Ozon poll listReportsForCompany failed — will retry next tick', [
                'companyId' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return new PollResult(seen: count($inFlight), updated: 0, finalized: 0, errors: 1);
        }

        $updated = 0;
        $finalized = 0;

        foreach ($inFlight as $row) {
            [$u, $f] = $this->reconcileOne($row, $ozonMap, $now);
            $updated += $u;
            $finalized += $f;
        }

        return new PollResult(
            seen: count($inFlight),
            updated: $updated,
            finalized: $finalized,
            errors: 0,
        );
    }

    /**
     * @param array<string, string> $ozonMap UUID => raw state из Ozon
     *
     * @return array{0: int, 1: int} [updated, finalized]
     */
    private function reconcileOne(
        OzonAdPendingReport $row,
        array $ozonMap,
        \DateTimeImmutable $now,
    ): array {
        $uuid = $row->getOzonUuid();
        $companyId = $row->getCompanyId();
        $nextAttempts = $row->getPollAttempts() + 1;
        $ageSeconds = $now->getTimestamp() - $row->getRequestedAt()->getTimestamp();

        // 1) Отсутствует в ответе — либо ещё не дошла до листинга, либо
        //    истёк TTL на стороне Ozon.
        if (!isset($ozonMap[$uuid])) {
            if ($ageSeconds >= self::MAX_AGE_BEFORE_ABANDON_SECONDS) {
                $this->repo->markFinalized(
                    $companyId,
                    $uuid,
                    OzonAdPendingReportState::ABANDONED,
                    sprintf('Missing from /statistics/list after %ds', $ageSeconds),
                );

                $this->logger->warning('Ozon pending report abandoned — not found in list', [
                    'companyId' => $companyId,
                    'reportUuid' => $uuid,
                    'ageSeconds' => $ageSeconds,
                ]);

                return [0, 1];
            }

            $this->repo->updateSchedule(
                $companyId,
                $uuid,
                $now,
                $this->computeNextPollAt($now, $nextAttempts),
                $nextAttempts,
            );

            return [1, 0];
        }

        // 2) Есть в листинге — решаем по rawState.
        $rawState = $ozonMap[$uuid];
        $normalizedUpper = strtoupper($rawState);

        if ($this->isTerminalOk($normalizedUpper)) {
            // OK/READY → фиксируем state=OK, гасим next_poll_at (дальше не
            // опрашиваем). markFinalized делает step 4 после скачивания CSV.
            $this->repo->updateStateWithSchedule(
                $companyId,
                $uuid,
                OzonAdPendingReportState::OK,
                $now,
                null,
                $nextAttempts,
                $now,
            );

            return [1, 0];
        }

        if ($this->isTerminalError($normalizedUpper)) {
            // ERROR/CANCELLED/NOT_FOUND → нормализуем в ERROR, в errorMessage
            // оставляем исходный rawState для диагностики.
            $this->repo->markFinalized(
                $companyId,
                $uuid,
                OzonAdPendingReportState::ERROR,
                sprintf('Ozon returned terminal state: %s', $rawState),
            );

            return [0, 1];
        }

        // 3) Non-terminal (NOT_STARTED, IN_PROGRESS, ...): записываем state
        //    «как есть», фиксируем firstNonPendingAt если это первый non-REQUESTED
        //    тик, планируем следующий poll.
        $this->repo->updateStateWithSchedule(
            $companyId,
            $uuid,
            $rawState,
            $now,
            $this->computeNextPollAt($now, $nextAttempts),
            $nextAttempts,
            OzonAdPendingReportState::NOT_STARTED === $normalizedUpper ? null : $now,
        );

        return [1, 0];
    }

    private function isTerminalOk(string $stateUpper): bool
    {
        return in_array($stateUpper, ['OK', 'READY'], true);
    }

    private function isTerminalError(string $stateUpper): bool
    {
        return in_array($stateUpper, ['ERROR', 'CANCELLED', 'NOT_FOUND'], true);
    }

    private function computeNextPollAt(\DateTimeImmutable $now, int $attempts): \DateTimeImmutable
    {
        // max($firstIdx, …) — belt-and-suspenders clamp: если где-то $attempts=0
        // просочится (programmer error), берём первый валидный индекс
        // вместо undefined-index crash.
        $firstIdx = array_key_first(self::BACKOFF_SCHEDULE_SECONDS);
        $lastIdx = array_key_last(self::BACKOFF_SCHEDULE_SECONDS);
        $idx = max($firstIdx, min($attempts, $lastIdx));
        $seconds = self::BACKOFF_SCHEDULE_SECONDS[$idx];

        return $now->modify(sprintf('+%d seconds', $seconds));
    }
}
