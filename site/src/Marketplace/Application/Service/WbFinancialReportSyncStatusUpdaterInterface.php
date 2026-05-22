<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MarketplaceRawDocument;

interface WbFinancialReportSyncStatusUpdaterInterface
{
    public function syncByRawPipelineResult(MarketplaceRawDocument $rawDocument, ?\Throwable $failure = null): void;
}
