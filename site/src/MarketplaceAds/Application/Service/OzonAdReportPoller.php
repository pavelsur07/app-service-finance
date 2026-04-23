<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Application\DTO\PollResult;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

/**
 * Per-company state machine для polling'а Ozon Performance отчётов.
 *
 * v1.17: переделано на per-UUID polling через GET /api/client/statistics/{uuid}
 * вместо GET /api/client/statistics/list. Инцидент 23.04.2026 показал, что
 * листинг отдаёт total=0 при реально готовых отчётах; per-UUID endpoint
 * работает надёжно. Rate-limit под контролем: backpressure v1.13 ограничивает
 * in-flight до 3 на company, поэтому максимум 3 HTTP-запроса на company за
 * тик cron'а (≈90 сек), Ozon это держит спокойно.
 *
 * Не скачивает отчёт: строки в state=OK остаются finalized_at=NULL, финальный
 * download + markFinalized делает DownloadOzonAdReportMessage handler.
 */
final class OzonAdReportPoller
{
    /**
     * Backoff в секундах, индекс = poll_attempts (1-based, clamp по верхней границе).
     *
     * computeNextPollAt() вызывается с $attempts = $pollAttempts + 1, поэтому
     * индекс 1 — schedule первого опроса после создания записи. Индекс 0
     * умышленно отсутствует: момент создания строки — не ответственность
     * этого сервиса. После 5-й попытки держим 10 минут — компромисс между
     * «не дёргать Ozon» и «не держать row в in-flight сутками».
     */
    private const BACKOFF_SCHEDULE_SECONDS = [
        1 => 30,
        2 => 60,
        3 => 120,
        4 => 300,
        5 => 600,
    ];

    /**
     * Строки старше этого возраста без финализации — force-ABANDONED.
     * Ограничивает lifetime «зомби-UUID», которые Ozon больше не обновляет.
     *
     * 3 часа — компромисс по итогам инцидента 22–23 апреля 2026: Ozon
     * Performance под нагрузкой держит отчёты в очереди генерации
     * 30–90 минут, редко до 2 часов. 3 часа = 99-й перцентиль по наблюдениям.
     */
    private const MAX_AGE_BEFORE_ABANDON_SECONDS = 10_800;

    public function __construct(
        private readonly OzonAdClient $client,
        private readonly OzonAdPendingReportRepository $repo,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId): PollResult
    {
        Assert::uuid($companyId);

        $inFlight = $this->repo->findInFlightByCompany($companyId);
        if ([] === $inFlight) {
            return new PollResult(seen: 0, updated: 0, finalized: 0, errors: 0);
        }

        $seen = 0;
        $updated = 0;
        $finalized = 0;
        $errors = 0;

        foreach ($inFlight as $row) {
            ++$seen;
            try {
                [$u, $f] = $this->pollAndReconcile($companyId, $row);
                $updated += $u;
                $finalized += $f;
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('Ozon poll report failed', [
                    'companyId' => $companyId,
                    'reportUuid' => $row->getOzonUuid(),
                    'pendingReportId' => $row->getId(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return new PollResult(
            seen: $seen,
            updated: $updated,
            finalized: $finalized,
            errors: $errors,
        );
    }

    /**
     * Опросить один UUID через /statistics/{uuid} и обновить БД на основе state.
     *
     * @return array{0: int, 1: int} [updated, finalized]
     */
    private function pollAndReconcile(string $companyId, OzonAdPendingReport $row): array
    {
        $uuid = $row->getOzonUuid();
        $now = new \DateTimeImmutable();

        $result = $this->client->pollOneReport($companyId, $uuid);
        $stateUpper = $result['state'];
        $nextAttempts = $row->getPollAttempts() + 1;

        if ($this->isTerminalOk($stateUpper)) {
            // Порядок строгий: UPDATE сначала, dispatch — только если UPDATE прошёл.
            // Защита от гонки: если запись уже финализирована параллельно (0 rows),
            // download нельзя дублировать. Контракт v1.16: «OK видна в БД до
            // прихода message» — соблюдается только при $updatedRows > 0.
            $updatedRows = $this->repo->updateStateWithSchedule(
                $companyId,
                $uuid,
                OzonAdPendingReportState::OK,
                $now,
                null,
                $nextAttempts,
                $now,
            );

            if (0 === $updatedRows) {
                $this->logger->warning('Ozon pending report OK — update returned 0 rows, skipping download dispatch', [
                    'companyId' => $companyId,
                    'reportUuid' => $uuid,
                    'pendingReportId' => $row->getId(),
                ]);

                return [0, 0];
            }

            $this->bus->dispatch(new DownloadOzonAdReportMessage(
                companyId: $companyId,
                pendingReportId: $row->getId(),
            ));

            return [1, 0];
        }

        if ($this->isTerminalError($stateUpper)) {
            $this->repo->markFinalized(
                $companyId,
                $uuid,
                OzonAdPendingReportState::ERROR,
                sprintf('Ozon report state=%s', $stateUpper),
            );

            return [0, 1];
        }

        // Non-terminal: обновляем state + next_poll_at. Неизвестные значения
        // маппим консервативно в IN_PROGRESS — продолжаем polling.
        $mappedState = $this->mapNonTerminalState($stateUpper);
        $nextPollAt = $this->computeNextPollAt($now, $nextAttempts);
        $firstNonPendingAt = OzonAdPendingReportState::NOT_STARTED === $mappedState ? null : $now;

        $this->repo->updateStateWithSchedule(
            $companyId,
            $uuid,
            $mappedState,
            $now,
            $nextPollAt,
            $nextAttempts,
            $firstNonPendingAt,
        );

        // Force-abandon если UUID слишком старый. Делается после updateStateWithSchedule
        // (полный polling-цикл не break'ается), затем overlay-финализация.
        $ageSeconds = $now->getTimestamp() - $row->getRequestedAt()->getTimestamp();
        if ($ageSeconds >= self::MAX_AGE_BEFORE_ABANDON_SECONDS) {
            $this->repo->markFinalized(
                $companyId,
                $uuid,
                OzonAdPendingReportState::ABANDONED,
                sprintf('Force-abandoned after %d seconds (Ozon state=%s)', $ageSeconds, $stateUpper),
            );

            return [0, 1];
        }

        return [1, 0];
    }

    /**
     * Маппинг non-terminal Ozon-состояний в наш enum. Неизвестные значения
     * трактуем как IN_PROGRESS — продолжаем polling без потери записи.
     */
    private function mapNonTerminalState(string $stateUpper): string
    {
        return match ($stateUpper) {
            'NOT_STARTED' => OzonAdPendingReportState::NOT_STARTED,
            'IN_PROGRESS' => OzonAdPendingReportState::IN_PROGRESS,
            default => OzonAdPendingReportState::IN_PROGRESS,
        };
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
        // Belt-and-suspenders clamp: если где-то $attempts=0 / негатив просочится
        // (programmer error), берём первый валидный индекс вместо undefined-index crash.
        $firstIdx = array_key_first(self::BACKOFF_SCHEDULE_SECONDS);
        $lastIdx = array_key_last(self::BACKOFF_SCHEDULE_SECONDS);
        $idx = max($firstIdx, min($attempts, $lastIdx));
        $seconds = self::BACKOFF_SCHEDULE_SECONDS[$idx];

        return $now->modify(sprintf('+%d seconds', $seconds));
    }
}
