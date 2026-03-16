<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Entity\MarketplaceJobLog;
use App\Marketplace\Enum\JobType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Inventory\Application\Command\ImportInventoryCostPriceFromFileCommand;
use App\Marketplace\Inventory\Application\ImportInventoryCostPriceFromFileAction;
use App\Marketplace\Message\ImportInventoryCostPriceMessage;
use App\Marketplace\Repository\MarketplaceJobLogRepository;
use App\Shared\Service\Storage\StorageService;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ImportInventoryCostPriceHandler
{
    public function __construct(
        private readonly ImportInventoryCostPriceFromFileAction $action,
        private readonly StorageService                        $storageService,
        private readonly MarketplaceJobLogRepository           $jobLogRepository,
        private readonly LoggerInterface                       $logger,
    ) {
    }

    public function __invoke(ImportInventoryCostPriceMessage $message): void
    {
        // Создаём запись лога со статусом running
        $jobLog = new MarketplaceJobLog(
            Uuid::uuid4()->toString(),
            $message->companyId,
            JobType::COST_PRICE_IMPORT,
        );
        $this->jobLogRepository->save($jobLog);

        $this->logger->info('[InventoryImport] Handler started', [
            'company_id'        => $message->companyId,
            'marketplace'       => $message->marketplace,
            'original_filename' => $message->originalFilename,
            'effective_from'    => $message->effectiveFrom,
        ]);

        try {
            $absolutePath = $this->storageService->getAbsolutePath($message->storagePath);

            if (!file_exists($absolutePath)) {
                throw new \RuntimeException('File not found: ' . $absolutePath);
            }

            $command = new ImportInventoryCostPriceFromFileCommand(
                companyId:        $message->companyId,
                absoluteFilePath: $absolutePath,
                originalFilename: $message->originalFilename,
                effectiveFrom:    new \DateTimeImmutable($message->effectiveFrom),
                marketplace:      MarketplaceType::from($message->marketplace),
            );

            $result = ($this->action)($command);

            // Формируем details из ошибок
            $details = array_map(
                static fn(string $error): array => ['reason' => $error],
                $result['errors'],
            );

            $summary = [
                'imported'  => $result['imported'],
                'skipped'   => $result['skipped'],
                'errors'    => count($result['errors']),
                'file'      => $message->originalFilename,
                'marketplace' => $message->marketplace,
            ];

            $jobLog->complete($summary, $details);
            $this->jobLogRepository->save($jobLog);

            $this->logger->info('[InventoryImport] Handler completed', [
                'company_id' => $message->companyId,
                'imported'   => $result['imported'],
                'skipped'    => $result['skipped'],
                'errors'     => count($result['errors']),
            ]);
        } catch (\Throwable $e) {
            $jobLog->fail($e->getMessage());
            $this->jobLogRepository->save($jobLog);

            $this->logger->error('[InventoryImport] Handler failed', [
                'company_id' => $message->companyId,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
