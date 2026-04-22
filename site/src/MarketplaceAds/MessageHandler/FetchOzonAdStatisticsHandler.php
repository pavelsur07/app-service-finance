<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Shared\Service\AppLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Async-обработчик {@see FetchOzonAdStatisticsMessage} — request-only.
 *
 * Step 5 redesign: воркер больше НЕ polling'ит Ozon синхронно и НЕ скачивает
 * отчёт. Его роль — только выставить POST /statistics и выйти (<30s на чанк
 * вместо прежних ~10 мин). Завершение ингеста берёт на себя связка
 * poll-cron → {@see DownloadOzonAdReportHandler}.
 *
 * Логика одного чанка:
 *  1) найти AdLoadJob (IDOR по company_id); если job удалён или в терминальном
 *     статусе — no-op;
 *  2) валидировать формат / календарь dateFrom / dateTo;
 *  3) вызвать {@see OzonAdClient::requestStatisticsOnly()} — он внутри
 *     разрешит credentials, получит token, сделает listSkuCampaigns,
 *     отфильтрует кампании, разобьёт на батчи ≤10 и на каждый батч
 *     выполнит POST /statistics с persist'ом OzonAdPendingReport
 *     (state=REQUESTED, jobId=этот job). Resume-логика внутри OzonAdClient
 *     защищает от дубликатов POST'ов при Messenger-retry (окно 900s);
 *  4) markChunkCompleted — идемпотентная фиксация чанка через
 *     AdChunkProgressRepository, SAME AS BEFORE. Семантика смещена:
 *     «chunk processed» → «chunk requested». При retry вернёт false
 *     и инкременты счётчиков пропустятся, чтобы не удвоить progress;
 *  5) RETURN. Никакого download, upsert AdRawDocument, dispatch
 *     ProcessAdRawDocumentMessage — всё это теперь делает
 *     DownloadOzonAdReportHandler после того, как poll-cron увидел
 *     state=OK у pending-отчёта.
 *
 * Политика ошибок:
 *  - \InvalidArgumentException (diapazon > 62 дней / from > to) — permanent
 *    bug вызывающей стороны → markFailed + UnrecoverableMessageHandlingException.
 *  - OzonPermanentApiException (403, missing credentials) — permanent denial →
 *    abandon всех in-flight pending-отчётов этого job'а (чтобы poll-cron не
 *    продолжал их опрашивать после markFailed), markFailed + Unrecoverable.
 *  - OzonAuthExpiredException (401) — внутри OzonAdClient::withAuthRetry один
 *    раз ретраится с fresh-токеном, наружу не выходит.
 *  - Прочие \Throwable (5xx, сеть, JSON-ошибки) — transient → rethrow,
 *    Messenger ретраит. Resume-логика внутри OzonAdClient найдёт уже
 *    созданный pending-отчёт (<900s) и пропустит re-POST.
 *
 * OzonStatisticsQueueFullException (NOT_STARTED timeout) старого sync-flow'а
 * здесь больше не ловится — он выбрасывался из внутреннего polling-цикла,
 * которого в async-режиме нет. Сам exception-класс не удалён (оставлен для
 * совместимости с cleanup-PR), но из этого handler'а catch убран.
 */
#[AsMessageHandler]
final class FetchOzonAdStatisticsHandler
{
    public function __construct(
        private readonly OzonAdClient $ozonAdClient,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly AdChunkProgressRepositoryInterface $adChunkProgressRepository,
        private readonly OzonAdPendingReportRepository $pendingReportRepo,
        private readonly AppLogger $logger,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $marketplaceAdsLogger,
    ) {
    }

    public function __invoke(FetchOzonAdStatisticsMessage $message): void
    {
        $job = $this->adLoadJobRepository->findByIdAndCompany(
            $message->jobId,
            $message->companyId,
        );

        if (null === $job) {
            $this->marketplaceAdsLogger->warning('AdLoadJob не найден при загрузке Ozon-чанка, сообщение проигнорировано', [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
            ]);

            return;
        }

        if ($job->getStatus()->isTerminal()) {
            $this->marketplaceAdsLogger->info('AdLoadJob уже завершён, загрузка чанка пропущена', [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'status' => $job->getStatus()->value,
            ]);

            return;
        }

        $dateFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->dateFrom);
        $dateTo = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->dateTo);

        // Round-trip сравнение с исходной строкой ловит календарно-невалидные
        // даты вроде 2026-02-31 — createFromFormat для них молча возвращает
        // нормализованный DateTimeImmutable (2026-03-03), а не false, из-за
        // чего без этой проверки handler грузил бы не тот диапазон.
        if (
            false === $dateFrom
            || false === $dateTo
            || $dateFrom->format('Y-m-d') !== $message->dateFrom
            || $dateTo->format('Y-m-d') !== $message->dateTo
        ) {
            $this->adLoadJobRepository->markFailed(
                $message->jobId,
                $message->companyId,
                sprintf('Invalid date format in message: from=%s, to=%s', $message->dateFrom, $message->dateTo),
            );

            throw new UnrecoverableMessageHandlingException(sprintf(
                'FetchOzonAdStatisticsMessage: invalid date format (from=%s, to=%s)',
                $message->dateFrom,
                $message->dateTo,
            ));
        }

        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);
        $chunkDays = (int) $dateFrom->diff($dateTo)->days + 1;

        try {
            $uuids = $this->ozonAdClient->requestStatisticsOnly(
                $message->companyId,
                $dateFrom,
                $dateTo,
                $message->jobId,
            );
        } catch (\InvalidArgumentException $e) {
            // Диапазон > 62 дней или from > to — баг вызывающего кода,
            // ретраить бессмысленно.
            $this->adLoadJobRepository->markFailed(
                $message->jobId,
                $message->companyId,
                'Invalid date range: '.$e->getMessage(),
            );

            throw new UnrecoverableMessageHandlingException(
                'FetchOzonAdStatisticsMessage: invalid date range — '.$e->getMessage(),
                0,
                $e,
            );
        } catch (OzonPermanentApiException $e) {
            // 403 / missing credentials — permanent denial. Abandon все
            // in-flight pending-отчёты этого job'а: job уходит в FAILED,
            // и poll-cron'у больше нечего делать с их UUID'ами. Без abandon'а
            // они остались бы висеть в REQUESTED до next_poll_at и
            // генерили бы лишние HTTP-запросы.
            $this->abandonInFlightForJob($message->companyId, $message->jobId, $e);

            $this->adLoadJobRepository->markFailed(
                $message->jobId,
                $message->companyId,
                'Ozon API permanent failure: '.$e->getMessage(),
            );

            throw new UnrecoverableMessageHandlingException(
                'FetchOzonAdStatisticsMessage: Ozon permanent failure — '.$e->getMessage(),
                0,
                $e,
            );
        } catch (\Throwable $e) {
            // Сетевые сбои / 5xx / JSON-ошибки — transient, Messenger сделает retry.
            // Resume-логика внутри OzonAdClient на повторе найдёт уже
            // созданный pending-отчёт (<900s) и пропустит дублирующий POST.
            $this->logger->error('Transient failure requesting Ozon ad statistics chunk', $e, [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'dateFrom' => $message->dateFrom,
                'dateTo' => $message->dateTo,
                'chunkDays' => $chunkDays,
            ]);

            throw $e;
        }

        // Идемпотентная фиксация чанка — в async-flow означает «запрос отправлен»,
        // а не «данные обработаны». При Messenger-retry markChunkCompleted
        // вернёт false (запись уже есть), и мы пропустим инкременты, не удвоив
        // loaded days счётчик.
        $marked = $this->adChunkProgressRepository->markChunkCompleted(
            $message->jobId,
            $message->companyId,
            $dateFrom,
            $dateTo,
        );

        if (!$marked) {
            $this->marketplaceAdsLogger->info('chunk already marked completed', [
                'job_id' => $message->jobId,
                'company_id' => $message->companyId,
                'date_from' => $message->dateFrom,
                'date_to' => $message->dateTo,
            ]);
        } else {
            // loaded_days считаем по покрытию чанка: в async-flow запрос покрыл
            // все дни диапазона, даже если за какие-то дни в итоге не окажется
            // кампаний — финальный ответ приедет через DownloadOzonAdReportHandler.
            $this->adLoadJobRepository->incrementLoadedDays(
                $message->jobId,
                $message->companyId,
                $chunkDays,
            );
        }

        $this->marketplaceAdsLogger->info('Ozon ad statistics chunk requested', [
            'jobId' => $message->jobId,
            'companyId' => $message->companyId,
            'dateFrom' => $message->dateFrom,
            'dateTo' => $message->dateTo,
            'chunkDays' => $chunkDays,
            'uuidsRequested' => count($uuids),
            'duplicate' => !$marked,
        ]);
    }

    /**
     * Финализирует все in-flight pending-отчёты job'а как ABANDONED.
     *
     * Вызывается из catch OzonPermanentApiException: раз job уходит в FAILED,
     * poll-cron'у не имеет смысла продолжать GET /statistics/list по этим
     * UUID. markFinalized идемпотентен (guard finalized_at IS NULL), так
     * что race с poll-cron'ом безопасен.
     */
    private function abandonInFlightForJob(
        string $companyId,
        string $jobId,
        OzonPermanentApiException $cause,
    ): void {
        $inFlight = $this->pendingReportRepo->findInFlightByJob($companyId, $jobId);
        if ([] === $inFlight) {
            return;
        }

        $reason = 'Job failed permanently: '.$cause->getMessage();
        foreach ($inFlight as $pending) {
            $this->pendingReportRepo->markFinalized(
                $pending->getCompanyId(),
                $pending->getOzonUuid(),
                OzonAdPendingReportState::ABANDONED,
                $reason,
            );
        }

        $this->marketplaceAdsLogger->info('Abandoned in-flight pending reports after permanent failure', [
            'jobId' => $jobId,
            'companyId' => $companyId,
            'abandonedCount' => count($inFlight),
        ]);
    }
}
