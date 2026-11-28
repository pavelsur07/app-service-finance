<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

interface MarketplaceRawDocumentMarketplaceQueryInterface
{
    /** @return array{marketplace: string, api_endpoint: string}|null */
    public function findDocumentSource(string $companyId, string $rawDocId): ?array;
}
