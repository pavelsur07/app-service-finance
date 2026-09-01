<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\RefreshOzonListingCatalogAction;
use App\Marketplace\Exception\OzonCatalogRateLimitException;
use App\Marketplace\Message\SyncOzonListingCatalogMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncOzonListingCatalogHandler
{
    public function __construct(
        private RefreshOzonListingCatalogAction $refreshOzonListingCatalogAction,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncOzonListingCatalogMessage $message): void
    {
        $this->logger->info('[OzonListingCatalog] Handler started.', [
            'company_id' => $message->companyId,
            'connection_id' => $message->connectionId,
        ]);

        try {
            $result = ($this->refreshOzonListingCatalogAction)($message->companyId, $message->connectionId);
        } catch (OzonCatalogRateLimitException $exception) {
            // 429 обрабатывается сам ретраем — это не инцидент и будить человека
            // незачем, поэтому warning, а не error (CLAUDE.md, «Логирование»).
            //
            // Исходное исключение пробрасывается как есть, БЕЗ обёртки в
            // RecoverableMessageHandlingException: Symfony считает
            // RecoverableExceptionInterface retryable безусловно, в обход
            // max_retries. Постоянный 429 крутил бы сообщение бесконечно и
            // никогда не дошёл бы до failed-очереди, где его видно. Обычное
            // исключение оставляет в силе retry_strategy транспорта async_sync
            // (3 попытки, delay 10s, multiplier 2).
            $this->logger->warning('[OzonListingCatalog] Rate limited, leaving retry to the transport.', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
            ]);

            throw $exception;
        }

        $this->logger->info('[OzonListingCatalog] Handler finished.', [
            'company_id' => $message->companyId,
            'connection_id' => $message->connectionId,
            'products_fetched' => $result->productsFetched,
            'listings_upserted' => $result->listingsUpserted,
            'raw_records_stored' => $result->rawRecordsStored,
        ]);
    }
}
