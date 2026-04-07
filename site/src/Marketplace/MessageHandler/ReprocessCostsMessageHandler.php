<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Facade\MarketplaceSyncFacade;
use App\Marketplace\Message\ReprocessCostsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReprocessCostsMessageHandler
{
    public function __construct(
        private MarketplaceSyncFacade $syncFacade,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReprocessCostsMessage $message): void
    {
        $this->logger->info('[ReprocessCosts] Processing raw document', [
            'raw_doc_id' => $message->rawDocumentId,
            'company_id' => $message->companyId,
        ]);

        $this->syncFacade->processCostsFromRaw($message->companyId, $message->rawDocumentId);

        $this->logger->info('[ReprocessCosts] Completed', [
            'raw_doc_id' => $message->rawDocumentId,
        ]);
    }
}
