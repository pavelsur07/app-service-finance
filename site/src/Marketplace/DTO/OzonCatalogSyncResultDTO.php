<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class OzonCatalogSyncResultDTO
{
    public function __construct(
        public int $productsFetched,
        public int $listingsUpserted,
        public int $rawRecordsStored,
    ) {
    }
}
