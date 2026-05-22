<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceRawDocument;

interface WbFinancialReportSyncStatusUpdaterInterface
{
    public function syncByRawPipelineResult(MarketplaceRawDocument $rawDocument, ?\Throwable $failure = null): void;

    public function markConflict(MarketplaceFinancialReportSyncStatus $status, string $errorClass, string $errorMessage, ?int $statusCode = null, ?string $responseExcerpt = null, ?array $requestPayload = null): void;
}
