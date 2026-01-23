<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Service\Dto\WbCommissionerReportRawIngestResult;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;

final class WbCommissionerReportRawIngestor
{
    public function ingest(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
        string $filePath,
    ): WbCommissionerReportRawIngestResult {
        return new WbCommissionerReportRawIngestResult(
            rowsTotal: 0,
            rowsParsed: 0,
            errorsCount: 0,
            warningsCount: 0,
        );
    }
}
