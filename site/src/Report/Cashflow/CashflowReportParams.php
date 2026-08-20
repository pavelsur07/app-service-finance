<?php

namespace App\Report\Cashflow;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;

final class CashflowReportParams
{
    /**
     * @param list<string>|null $projectDirectionIds
     * @param list<string>|null $responsibilityCenterIds
     * @param list<ProjectDirection>|null $availableProjectDirections
     */
    public function __construct(
        public readonly Company $company,
        public readonly string $group,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly ?string $responsibilityCenterId = null,
        public readonly ?array $projectDirectionIds = null,
        public readonly ?array $responsibilityCenterIds = null,
        public readonly ?array $availableProjectDirections = null,
    ) {
    }
}
