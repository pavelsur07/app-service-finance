<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Inventory\Application\Command\ImportInventoryCostPriceFromFileCommand;
use App\Marketplace\Inventory\Application\ImportInventoryCostPriceFromFileAction;
use App\Marketplace\Message\ImportInventoryCostPriceMessage;
use App\Shared\Service\Storage\StorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ImportInventoryCostPriceHandler
{
    public function __construct(
        private readonly ImportInventoryCostPriceFromFileAction $action,
        private readonly StorageService                        $storageService,
        private readonly LoggerInterface                       $logger,
    ) {
    }

    public function __invoke(ImportInventoryCostPriceMessage $message): void
    {
        $this->logger->info('[InventoryImport] Handler started', [
            'company_id'        => $message->companyId,
            'marketplace'       => $message->marketplace,
            'storage_path'      => $message->storagePath,
            'original_filename' => $message->originalFilename,
            'effective_from'    => $message->effectiveFrom,
        ]);

        $absolutePath = $this->storageService->getAbsolutePath($message->storagePath);

        if (!file_exists($absolutePath)) {
            $this->logger->error('[InventoryImport] File not found', [
                'absolute_path' => $absolutePath,
            ]);

            return;
        }

        try {
            $command = new ImportInventoryCostPriceFromFileCommand(
                companyId:        $message->companyId,
                absoluteFilePath: $absolutePath,
                originalFilename: $message->originalFilename,
                effectiveFrom:    new \DateTimeImmutable($message->effectiveFrom),
                marketplace:      MarketplaceType::from($message->marketplace),
            );

            $result = ($this->action)($command);

            $this->logger->info('[InventoryImport] Handler completed', [
                'company_id' => $message->companyId,
                'imported'   => $result['imported'],
                'skipped'    => $result['skipped'],
                'errors'     => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[InventoryImport] Handler failed', [
                'company_id' => $message->companyId,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
