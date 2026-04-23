<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Application\Service\AdLoadJobFinalizer;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Message\RequestOzonAdBatchMessage;
use App\MarketplaceAds\Repository\AdChunkProgressRepositoryInterface;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use App\Shared\Service\AppLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Async-обработчик {@see FetchOzonAdStatisticsMessage} — orchestrator'ом one-
 * POST-per-message pipeline'а.
 *
 * Шаг 5 redesign'а сделал этот handler request-only (без polling'а и без
 * download'а). Follow-up fix (текущий PR): сам POST /statistics вынесен
 * в {@see RequestOzonAdBatchHandler} — Ozon Performance API разрешает
 * ровно 1 активный запрос к /statistics на аккаунт, и дёргать N батчей
 * back-to-back из одного handler'а ловило 429 начиная со 2-го батча.
 *
 * Этот handler теперь:
 *  1) находит AdLoadJob (IDOR-guard по company_id); job отсутствует /
 *     в терминальном статусе — no-op;
 *  2) валидирует формат и календарь dateFrom / dateTo;
 *  3) вызывает {@see OzonAdClient::prepareStatisticsBatches()} — она
 *     резолвит credentials, получает токен, делает listSkuCampaigns,
 *     фильтрует recency-cutoff'ом и возвращает список батчей по ≤10
 *     campaign_id. Никаких POST /statistics здесь не происходит;
 *  4) для каждого батча диспатчит {@see RequestOzonAdBatchMessage}
 *     (транспорт async_ads, single worker + FIFO). Единственный worker
 *     поочерёдно делает по одному POST, серilизуя Ozon'овский лимит без
 *     sleep'ов и без intra-handler parallelism'а;
 *  5) markChunkCompleted + incrementLoadedDays — семантика «chunk
 *     dispatched» (как и в предыдущем шаге 5). При Messenger-retry
 *     markChunkCompleted вернёт false и счётчики не удвоятся;
 *  6) zero-batches ветка: если prepareStatisticsBatches вернул [] (нет
 *     активных SKU-кампаний / всё отфильтровано по recency-cutoff'у), ни
 *     одного OzonAdPendingReport не появится — значит poll-cron'у нечего
 *     опрашивать, и zero-docs финализация в DownloadOzonAdReportHandler
 *     не сработает. Вызываем {@see AdLoadJobFinalizer::tryFinalize()}
 *     напрямую, иначе job навечно залип бы в RUNNING.
 *
 * Политика ошибок:
 *  - \InvalidArgumentException (диапазон > 62 дней / from > to) — permanent
 *    баг вызывающей стороны → markFailed + Unrecoverable.
 *  - OzonPermanentApiException (403, missing credentials) — permanent denial →
 *    abandon всех in-flight pending-отчётов этого job'а (созданных ранее на
 *    предыдущих чанках), markFailed + Unrecoverable. В новом дизайне
 *    prepareStatisticsBatches сам по себе pending-отчётов не создаёт, но
 *    in-flight из предыдущих чанков того же job'а ещё могут полевать в cron.
 *  - OzonAuthExpiredException (401) — внутри OzonAdClient::withAuthRetry один
 *    раз ретраится с fresh-токеном, наружу не выходит.
 *  - Прочие \Throwable (5xx, сеть, JSON-ошибки на prep-шаге; transient
 *    ошибки dispatch'а на Redis) — transient → rethrow, Messenger ретраит.
 *    При ретрае matchResumableReport внутри RequestOzonAdBatchHandler
 *    предохранит от повторных POST'ов уже-выданных UUID.
 *
 * Сам POST /statistics больше не делается в этом handler'е; catch 429
 * переехал в downstream RequestOzonAdBatchHandler, где его Messenger ретраит
 * штатно по расписанию async_ads.
 */
#[AsMessageHandler]
final class FetchOzonAdStatisticsHandler
{
    public function __construct(
        private readonly OzonAdClient $ozonAdClient,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly AdChunkProgressRepositoryInterface $adChunkProgressRepository,
        private readonly OzonAdPendingReportRepository $pendingReportRepo,
        private readonly AdLoadJobFinalizer $finalizer,
        private readonly MessageBusInterface $messageBus,
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
            $batches = $this->ozonAdClient->prepareStatisticsBatches(
                $message->companyId,
                $dateFrom,
                $dateTo,
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
            // in-flight pending-отчёты этого job'а (из предыдущих чанков /
            // retry'ев): job уходит в FAILED, и poll-cron'у больше нечего
            // делать с их UUID'ами. Без abandon'а они остались бы висеть
            // в REQUESTED до next_poll_at и генерили бы лишние HTTP-запросы.
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
            // Сетевые сбои / 5xx / JSON-ошибки на prep-стадии — transient,
            // Messenger сделает retry. prepareStatisticsBatches идемпотентен
            // (ничего не персистит), повтор безопасен.
            $this->logger->error('Transient failure preparing Ozon ad statistics batches', $e, [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'dateFrom' => $message->dateFrom,
                'dateTo' => $message->dateTo,
                'chunkDays' => $chunkDays,
            ]);

            throw $e;
        }

        // Диспатч по одному сообщению на батч — single async_ads worker
        // обработает их последовательно. Ozon лимит «3 активных отчёта/аккаунт»
        // обеспечивается backpressure-гейтом в RequestOzonAdBatchHandler
        // (COUNT in-flight pending_reports до POST'а). matchResumableReport
        // внутри RequestOzonAdBatchHandler даёт идемпотентность при Messenger-retry.
        $batchTotal = count($batches);
        foreach ($batches as $batchIndex => $campaignIds) {
            // Ozon /statistics жёстко троттлит при back-to-back POST'ах на аккаунт
            // (25 подряд → устойчивые 429, которые не пробить даже 10 ретраями).
            // Разносим batch'и на 90 секунд — Ozon'у хватает обработать предыдущий
            // запрос до того, как прилетит следующий. batchIndex=0 идёт без задержки.
            $stamps = [];
            if ($batchIndex > 0) {
                $stamps[] = new DelayStamp($batchIndex * 90_000);
            }

            $this->messageBus->dispatch(
                new RequestOzonAdBatchMessage(
                    companyId: $message->companyId,
                    jobId: $message->jobId,
                    dateFrom: $message->dateFrom,
                    dateTo: $message->dateTo,
                    campaignIds: $campaignIds,
                    batchIndex: $batchIndex,
                    batchTotal: $batchTotal,
                ),
                $stamps,
            );
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

        // Zero-batches branch: prepareStatisticsBatches вернул пустой список
        // (нет активных SKU-кампаний или все отфильтровались recency-cutoff'ом).
        // Значит ни один RequestOzonAdBatchMessage не будет диспатчен, ни
        // одного OzonAdPendingReport не создано, poll-cron'у нечего опрашивать,
        // и zero-docs финализация в DownloadOzonAdReportHandler никогда не
        // сработает для этого чанка. Без прямого вызова tryFinalize здесь job
        // навечно застрял бы в RUNNING — это восстанавливает гарантию из
        // старого sync-обработчика (commit 7d00eb0) на новом уровне пайплайна.
        // tryFinalize идемпотентен: если другие чанки ещё in-flight, он
        // безопасно вернётся без изменений.
        if ([] === $batches) {
            $this->finalizer->tryFinalize(
                $message->jobId,
                $message->companyId,
            );

            $this->marketplaceAdsLogger->info(
                'Ozon ad statistics chunk had no active campaigns — finalizing directly',
                [
                    'jobId' => $message->jobId,
                    'companyId' => $message->companyId,
                    'dateFrom' => $message->dateFrom,
                    'dateTo' => $message->dateTo,
                ],
            );
        }

        $this->marketplaceAdsLogger->info('Ozon ad statistics chunk dispatched', [
            'jobId' => $message->jobId,
            'companyId' => $message->companyId,
            'dateFrom' => $message->dateFrom,
            'dateTo' => $message->dateTo,
            'chunkDays' => $chunkDays,
            'batchesDispatched' => $batchTotal,
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
