<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;

interface MarketplaceFinancialReportSyncStatusLookupInterface
{
    public function findByRawDocumentId(string $companyId, string $rawDocumentId): ?MarketplaceFinancialReportSyncStatus;
}
