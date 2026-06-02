<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceRawDocument;

interface WbFinancialReportSyncStatusUpdaterInterface
{
    /** @param array{sync_status_id?: string|null, company_id?: string|null, connection_id?: string|null, marketplace?: string|null, report_type?: string|null, mode?: string|null, business_date?: string|null, raw_document_id?: string|null}|null $context */
    public function syncByRawPipelineResult(MarketplaceRawDocument $rawDocument, ?\Throwable $failure = null, ?array $context = null): void;

    public function markConflict(MarketplaceFinancialReportSyncStatus $status, string $errorClass, string $errorMessage, ?int $statusCode = null, ?string $responseExcerpt = null, ?array $requestPayload = null): void;
}
