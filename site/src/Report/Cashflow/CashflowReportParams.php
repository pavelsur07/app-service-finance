<?php

namespace App\Report\Cashflow;

use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashflowFlowKind;
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
        public readonly ?string $dashboardActivity = null,
        public readonly ?FiatCurrency $dashboardCurrency = null,
    ) {
    }

    public function isDashboardReconciliation(): bool
    {
        return null !== $this->dashboardActivity && null !== $this->dashboardCurrency;
    }

    public function dashboardFlowKind(): ?CashflowFlowKind
    {
        return match ($this->dashboardActivity) {
            'operating' => CashflowFlowKind::OPERATING,
            'financing' => CashflowFlowKind::FINANCING,
            'investing' => CashflowFlowKind::INVESTING,
            default => null,
        };
    }
}
