<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Service\MarketplaceSyncService;

final class OzonCostsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(private readonly MarketplaceSyncService $syncService)
    {
    }

    public function supports(string|StagingRecordType $type, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::COST;
        }

        return $type === 'ozon' && $kind === 'costs';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return $this->syncService->processOzonCostsFromRaw($companyId, $rawDocId);
    }
}
