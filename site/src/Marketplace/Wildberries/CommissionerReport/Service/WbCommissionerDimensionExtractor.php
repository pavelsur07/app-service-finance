<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Service\Dto\WbCommissionerDimensionExtractResult;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;

final class WbCommissionerDimensionExtractor
{
    public function extract(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
    ): WbCommissionerDimensionExtractResult {
        return new WbCommissionerDimensionExtractResult(dimensionsTotal: 0);
    }
}
