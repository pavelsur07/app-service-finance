<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

use App\MarketplaceAds\Enum\AdRawDocumentStatus;

final readonly class WbAdSpendLoadResult
{
    public function __construct(
        public string $rawDocumentId,
        public AdRawDocumentStatus $status,
        public int $campaignCount,
        public int $skuCount,
        public string $attributedTotal,
        public string $unallocatedTotal,
        public string $persistedUnallocatedTotal,
        public string $actualTotal,
        public string $documentTotal,
        public string $lineTotal,
        public string $withoutLineTotal,
        public string $unmappedTotal,
        public int $unmappedCount,
        public bool $reconciled,
    ) {
    }
}
