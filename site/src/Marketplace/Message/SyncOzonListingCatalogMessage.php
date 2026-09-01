<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

final readonly class SyncOzonListingCatalogMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
    ) {
    }
}
