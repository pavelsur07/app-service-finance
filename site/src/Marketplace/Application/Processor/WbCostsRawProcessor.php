<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Service\MarketplaceSyncService;

final class WbCostsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(private readonly MarketplaceSyncService $syncService)
    {
    }

    public function supports(string|StagingRecordType $type, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::COST;
        }

        return $type === 'wildberries' && $kind === 'costs';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return $this->syncService->processWbCostsFromRaw($companyId, $rawDocId);
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        // TODO: Будет реализовано в PR 5 (Перевод Costs и Returns на новую архитектуру).
        // Пока оставляем пустым, чтобы Демультиплексор мог безопасно маршрутизировать эти строки без падения.
    }
}
