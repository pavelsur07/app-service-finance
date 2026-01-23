<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Service\Dto\WbCommissionerAggregationResult;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;

final class WbCommissionerAggregationCalculator
{
    public function calculate(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
    ): WbCommissionerAggregationResult {
        return new WbCommissionerAggregationResult(success: true);
    }
}
