<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Message\RequestOzonAdBatchMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

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
 *  - Transient errors (429, 5xx, сеть) — пробрасываются, Messenger ретраит
 *    по расписанию async_ads (2 попытки, 30с базовой задержки, ×2). На
 *    retry matchResumableReport внутри requestOneBatch находит уже
 *    созданный pending-отчёт и пропускает re-POST (окно 900с).
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

    public function __construct(
        private readonly AdLoadJobRepository $jobRepository,
        private readonly OzonAdClient $ozonAdClient,
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
