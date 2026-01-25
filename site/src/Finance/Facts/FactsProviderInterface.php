<?php

declare(strict_types=1);

namespace App\Finance\Facts;

use App\Company\Entity\ProjectDirection;
use App\Entity\Company;
use App\Finance\Report\PlReportPeriod;

interface FactsProviderInterface
{
    public function value(Company $company, PlReportPeriod $period, string $code, ?ProjectDirection $projectDirection = null): float;
}
