<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

interface MarketplaceRawDocumentMarketplaceQueryInterface
{
    public function getMarketplaceValue(string $companyId, string $rawDocId): ?string;
}
