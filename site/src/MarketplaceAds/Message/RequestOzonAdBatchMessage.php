<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Message;

/**
 * One POST /api/client/statistics per message. Serializes Ozon's
 * "max 1 active request" rate limit through the single async_ads worker
 * consuming from Redis FIFO.
 *
 * Dispatched by {@see \App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler}
 * once per batch (≤10 campaigns). Handled by
 * {@see \App\MarketplaceAds\MessageHandler\RequestOzonAdBatchHandler}, which is
 * the only place the new pipeline calls
 * {@see \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient::requestOneBatch()}.
 *
 * Scalar-only payload per Messenger/CLAUDE.md rules. batchIndex + batchTotal
 * are optional for logic but помогают скоррелировать логи с исходным чанком
 * при дебаге.
 */
final readonly class RequestOzonAdBatchMessage
{
    /**
     * @param list<string> $campaignIds up to STATISTICS_BATCH_SIZE=10 entries
     */
    public function __construct(
        public string $companyId,
        public string $jobId,
        public string $dateFrom,
        public string $dateTo,
        public array $campaignIds,
        public int $batchIndex,
        public int $batchTotal,
    ) {
    }
}
