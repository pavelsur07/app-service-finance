<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Exception\OzonRateLimitException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\RequestOzonAdBatchMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Async-обработчик {@see RequestOzonAdBatchMessage}: ровно один POST
 * /api/client/statistics на сообщение.
 *
 * Ozon Performance API разрешает не более 1 активного запроса к /statistics
 * на аккаунт. Single-worker транспорт async_ads + FIFO Redis транзит
 * последовательно обрабатывают N таких сообщений — каждый POST завершается
 * за ~200мс, следующий в очереди идёт после, никакого 429-а.
 *
 * Политика ошибок:
 *  - Job в терминальном статусе / не найден → no-op, ack.
 *  - {@see OzonPermanentApiException} (403 / scope revoked) → markFailed на
 *    job'е + UnrecoverableMessageHandlingException. Оставшиеся батчи тоже
 *    упадут с «job уже терминален» и станут no-op.
 *  - {@see OzonRateLimitException} (429) — это НЕ «ретраить через 30с»:
 *    Ozon меряет «1 active request» на своей стороне через занятость
 *    UUID-creation slot'а (30–60с), что переживает наш HTTP round-trip.
 *    Handler диспатчит то же сообщение обратно с {@see DelayStamp} на 60с,
 *    инкрементит `rateLimitAttempts` и возвращается нормально (ACK — без
 *    Messenger-ретрая). После {@see self::MAX_RATE_LIMIT_ATTEMPTS} попыток
 *    батч помечается как failed (same-as-permanent).
 *  - Остальные transient errors (5xx, сеть) — пробрасываются, Messenger
 *    ретраит по расписанию async_ads. На retry matchResumableReport внутри
 *    requestOneBatch находит уже созданный pending-отчёт и пропускает
 *    re-POST (окно 900с).
 */
#[AsMessageHandler]
final class RequestOzonAdBatchHandler
{
    /**
     * Ozon Performance API: «Превышен лимит по количеству кампаний (максимум 10)».
     * Orchestrator бьёт batches через array_chunk(..., 10), так что в норме
     * каждое сообщение приходит с 1..10 id. Но если кто-то диспатчит сообщение
     * руками / из CLI в обход orchestrator'а с >10 id — Ozon вернёт 4xx,
     * который handler бы ретраил бесконечно (4xx у нас transient). Явный
     * guard с Unrecoverable ловит это один раз и bounce'ит в dead-letter.
     */
    private const STATISTICS_BATCH_SIZE = 10;

    /**
     * Ozon "1 active request" backend slot occupancy is 30-60s typically.
     * 60s gives us a safety margin; jitter is naturally added by the
     * moment we catch 429 (which is itself time-shifted by HTTP latency).
     */
    private const RATE_LIMIT_BACKOFF_MS = 60_000;

    /**
     * 10 * 60s = 10 min max delay per batch before we give up and mark
     * the job failed. Беспредельный loop невозможен — Ozon либо отдаст
     * 200 в пределах этого окна, либо проблема на их стороне затянулась
     * и инженерам стоит посмотреть руками.
     */
    private const MAX_RATE_LIMIT_ATTEMPTS = 10;

    /**
     * Ozon Performance жёсткий лимит: не более 3 активных отчётов в очереди
     * на один Performance-аккаунт. Превышение → 429 "Превышен лимит активных".
     * Этот guard делает проверку на СТОРОНЕ КЛИЕНТА, до POST, по БД —
     * предсказуемее, чем ловить 429 от Ozon.
     *
     * ВАЖНО: корректность этого guard'а зависит от того, что транспорт
     * async_ads остаётся single-worker + FIFO. При >1 worker'е read-modify-write
     * «COUNT → dispatch POST» становится гонкой: два worker'а могут одновременно
     * увидеть count=2 и создать 4-й слот. Если будете поднимать параллелизм —
     * замените на SELECT ... FOR UPDATE или Redis-lock per company_id.
     */
    private const MAX_IN_FLIGHT_PER_COMPANY = 3;

    /**
     * Backpressure delay: если слотов нет — ждём минуту и возвращаемся.
     * Тот же порядок, что и RATE_LIMIT_BACKOFF_MS — для консистентности.
     */
    private const BACKPRESSURE_BACKOFF_MS = 60_000;

    public function __construct(
        private readonly AdLoadJobRepository $jobRepository,
        private readonly OzonAdClient $ozonAdClient,
        private readonly OzonAdPendingReportRepository $pendingReportRepo,
        private readonly MessageBusInterface $messageBus,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $marketplaceAdsLogger,
    ) {
    }

    public function __invoke(RequestOzonAdBatchMessage $message): void
    {
        $job = $this->jobRepository->findByIdAndCompany(
            $message->jobId,
            $message->companyId,
        );

        if (null === $job || $job->getStatus()->isTerminal()) {
            $this->marketplaceAdsLogger->info('RequestOzonAdBatchMessage: job gone or terminal — skipping', [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'status' => null === $job ? 'missing' : $job->getStatus()->value,
                'batchIndex' => $message->batchIndex,
                'batchTotal' => $message->batchTotal,
            ]);

            return;
        }

        // Backpressure: Ozon лимит = 3 активных отчёта/аккаунт. Превышение →
        // 429 "Превышен лимит активных". Проверяем ДО POST'а через БД — если
        // у company уже 3+ in-flight, не тратим HTTP и slot Ozon'а, откладываем
        // message на 60 секунд. rateLimitAttempts НЕ инкрементим — это pre-check,
        // а не 429. Цикл продолжается пока слоты не освободятся; верхняя граница —
        // не число попыток, а lifecycle AdLoadJob: когда job помечается FAILED
        // через другие пути (permanent error / bad date range / таймаут операции),
        // этот message тоже выйдет из цикла через первую же проверку
        // `$job->getStatus()->isTerminal()` в начале __invoke.
        $inFlight = $this->pendingReportRepo->countInFlightByCompany($message->companyId);
        if ($inFlight >= self::MAX_IN_FLIGHT_PER_COMPANY) {
            $this->messageBus->dispatch(
                new Envelope($message),
                [new DelayStamp(self::BACKPRESSURE_BACKOFF_MS)],
            );

            $this->marketplaceAdsLogger->info('RequestOzonAdBatchMessage: backpressure — slots exhausted, rescheduled', [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'batchIndex' => $message->batchIndex,
                'batchTotal' => $message->batchTotal,
                'inFlight' => $inFlight,
                'maxInFlight' => self::MAX_IN_FLIGHT_PER_COMPANY,
                'delayMs' => self::BACKPRESSURE_BACKOFF_MS,
            ]);

            return;
        }

        // Размер батча должен быть 1..STATISTICS_BATCH_SIZE. Orchestrator это
        // гарантирует; попадание сюда означает permanent bug у вызывающего.
        $batchSize = count($message->campaignIds);
        if ($batchSize < 1 || $batchSize > self::STATISTICS_BATCH_SIZE) {
            throw new UnrecoverableMessageHandlingException(sprintf(
                'RequestOzonAdBatchMessage: campaignIds size %d out of [1..%d]',
                $batchSize,
                self::STATISTICS_BATCH_SIZE,
            ));
        }

        $dateFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->dateFrom);
        $dateTo = \DateTimeImmutable::createFromFormat('!Y-m-d', $message->dateTo);

        if (
            false === $dateFrom
            || false === $dateTo
            || $dateFrom->format('Y-m-d') !== $message->dateFrom
            || $dateTo->format('Y-m-d') !== $message->dateTo
        ) {
            // Не должно случаться: orchestrator передаёт заранее провалидированные
            // строки. Но если вдруг — permanent bug, ретрай бессмыслен.
            throw new UnrecoverableMessageHandlingException(sprintf(
                'RequestOzonAdBatchMessage: invalid date format (from=%s, to=%s)',
                $message->dateFrom,
                $message->dateTo,
            ));
        }

        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);

        try {
            $this->ozonAdClient->requestOneBatch(
                $message->companyId,
                $message->jobId,
                $dateFrom,
                $dateTo,
                $message->campaignIds,
            );
        } catch (OzonPermanentApiException $e) {
            // 403 / scope revoked — весь job обречён. markFailed идемпотентен,
            // если другой батч уже успел его зафейлить.
            $this->marketplaceAdsLogger->warning('RequestOzonAdBatchMessage: Ozon API permanently denied', [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'batchIndex' => $message->batchIndex,
                'batchTotal' => $message->batchTotal,
                'error' => $e->getMessage(),
            ]);

            $this->jobRepository->markFailed(
                $message->jobId,
                $message->companyId,
                'Ozon Performance: '.$e->getMessage(),
            );

            throw new UnrecoverableMessageHandlingException(
                'RequestOzonAdBatchMessage: Ozon permanent failure — '.$e->getMessage(),
                0,
                $e,
            );
        } catch (OzonRateLimitException $e) {
            // Ozon backend busy with another /statistics request on this
            // account. Reschedule THIS message with a 60s delay. This does
            // NOT consume a Messenger retry attempt — current message is
            // ACK'd (return normally, not throw), and a fresh copy goes back
            // to async_ads with a 60-second wait.
            //
            // matchResumableReport on the rescheduled attempt still protects
            // against double-POST if Ozon's slot freed up and we slip through
            // while a prior attempt also lands.
            if ($message->rateLimitAttempts >= self::MAX_RATE_LIMIT_ATTEMPTS) {
                $this->marketplaceAdsLogger->warning('RequestOzonAdBatchMessage: exceeded rate-limit attempt cap — giving up', [
                    'jobId' => $message->jobId,
                    'companyId' => $message->companyId,
                    'batchIndex' => $message->batchIndex,
                    'batchTotal' => $message->batchTotal,
                    'attempts' => $message->rateLimitAttempts,
                ]);

                $this->jobRepository->markFailed(
                    $message->jobId,
                    $message->companyId,
                    sprintf('Ozon Performance: rate-limited >%d attempts — aborting batch', self::MAX_RATE_LIMIT_ATTEMPTS),
                );

                throw new UnrecoverableMessageHandlingException(
                    'RequestOzonAdBatchMessage: Ozon rate limit exhausted',
                    0,
                    $e,
                );
            }

            $next = new RequestOzonAdBatchMessage(
                companyId: $message->companyId,
                jobId: $message->jobId,
                dateFrom: $message->dateFrom,
                dateTo: $message->dateTo,
                campaignIds: $message->campaignIds,
                batchIndex: $message->batchIndex,
                batchTotal: $message->batchTotal,
                rateLimitAttempts: $message->rateLimitAttempts + 1,
            );

            $this->messageBus->dispatch(
                new Envelope($next),
                [new DelayStamp(self::RATE_LIMIT_BACKOFF_MS)],
            );

            $this->marketplaceAdsLogger->info('RequestOzonAdBatchMessage: Ozon 429 — rescheduled with delay', [
                'jobId' => $message->jobId,
                'companyId' => $message->companyId,
                'batchIndex' => $message->batchIndex,
                'batchTotal' => $message->batchTotal,
                'attempts' => $next->rateLimitAttempts,
                'delayMs' => self::RATE_LIMIT_BACKOFF_MS,
            ]);

            // ACK current message — reschedule is the retry.
            return;
        }

        $this->marketplaceAdsLogger->info('RequestOzonAdBatchMessage: batch requested', [
            'jobId' => $message->jobId,
            'companyId' => $message->companyId,
            'batchIndex' => $message->batchIndex,
            'batchTotal' => $message->batchTotal,
            'campaignCnt' => count($message->campaignIds),
        ]);
    }
}
