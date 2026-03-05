<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Service\MarketplaceSyncService;

final class OzonSalesRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(private readonly MarketplaceSyncService $syncService)
    {
    }

    public function supports(string $marketplaceValue, string $kind): bool
    {
        return $marketplaceValue === 'ozon' && $kind === 'sales';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return $this->syncService->processOzonSalesFromRaw($companyId, $rawDocId);
    }
}
