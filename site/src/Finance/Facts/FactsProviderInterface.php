<?php

declare(strict_types=1);

namespace App\Finance\Facts;

use App\Entity\Company;
use App\Entity\ProjectDirection;
use App\Finance\Report\PlReportPeriod;

interface FactsProviderInterface
{
    public function value(Company $company, PlReportPeriod $period, string $code, ?ProjectDirection $projectDirection = null): float;
}
