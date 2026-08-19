<?php

declare(strict_types=1);

namespace App\Finance\Facts;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Report\PlReportPeriod;

interface FactsProviderInterface
{
    /**
     * @param ProjectDirection|list<ProjectDirection>|null $projectDirection
     * @param string|list<string>|null $responsibilityCenterId
     */
    public function value(
        Company $company,
        PlReportPeriod $period,
        string $code,
        ProjectDirection|array|null $projectDirection = null,
        string|array|null $responsibilityCenterId = null,
    ): float;
}
