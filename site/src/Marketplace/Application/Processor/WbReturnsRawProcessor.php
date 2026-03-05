<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Service\MarketplaceSyncService;

final class WbReturnsRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(private readonly MarketplaceSyncService $syncService)
    {
    }

    public function supports(string $marketplaceValue, string $kind): bool
    {
        return $marketplaceValue === 'wildberries' && $kind === 'returns';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return $this->syncService->processWbReturnsFromRaw($companyId, $rawDocId);
    }
}
