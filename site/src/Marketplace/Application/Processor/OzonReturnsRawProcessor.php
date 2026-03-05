<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Service\MarketplaceSyncService;

final class OzonReturnsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(private readonly MarketplaceSyncService $syncService)
    {
    }

    public function supports(string $marketplaceValue, string $kind): bool
    {
        return $marketplaceValue === 'ozon' && $kind === 'returns';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return $this->syncService->processOzonReturnsFromRaw($companyId, $rawDocId);
    }
}
