<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service\CommissionerReport;

use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Service\CommissionerReport\Dto\ImportResultDTO;

final class WbCommissionerXlsxImporter
{
    public function import(WildberriesCommissionerXlsxReport $report, string $absoluteFilePath): ImportResultDTO
    {
        return new ImportResultDTO(
            rowsTotal: $report->getRowsTotal(),
            rowsParsed: $report->getRowsParsed(),
            errorsCount: $report->getErrorsCount(),
            warningsCount: $report->getWarningsCount(),
        );
    }
}
