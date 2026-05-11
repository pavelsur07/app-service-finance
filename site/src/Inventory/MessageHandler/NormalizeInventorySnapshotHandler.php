<?php

declare(strict_types=1);

namespace App\Inventory\MessageHandler;

use App\Inventory\Application\NormalizeInventorySnapshotAction;
use App\Inventory\Message\NormalizeInventorySnapshotMessage;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\AppLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NormalizeInventorySnapshotHandler
{
    public function __construct(
        private NormalizeInventorySnapshotAction $action,
        private AppLogger $logger,
    ) {}

    public function __invoke(NormalizeInventorySnapshotMessage $message): void
    {
        $this->logger->info('Inventory normalization started.', [
            'companyId' => $message->companyId,
            'snapshotSessionId' => $message->snapshotSessionId,
            'source' => $message->source,
        ]);

        $source = MarketplaceType::tryFrom($message->source);
        if ($source === null) {
            $this->logger->warning('Inventory normalization skipped due to unsupported source value.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $message->snapshotSessionId,
                'source' => $message->source,
            ]);
            return;
        }

        try {
            ($this->action)($message->companyId, $message->snapshotSessionId, $source);
            $this->logger->info('Inventory normalization finished.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $message->snapshotSessionId,
                'source' => $message->source,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Inventory normalization failed.', [
                'companyId' => $message->companyId,
                'snapshotSessionId' => $message->snapshotSessionId,
                'source' => $message->source,
                'exceptionClass' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
