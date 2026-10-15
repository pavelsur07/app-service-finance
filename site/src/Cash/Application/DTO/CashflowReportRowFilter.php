<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashflowFlowKind;

/**
 * Фильтры строк отчёта ДДС; null — фильтр не применяется.
 *
 * `dashboardScope` повторяет охват KPI дашборда (`sumGrossTurnoverExcludingTransfers`):
 * валюта `dashboardCurrency`, без переводов и технических статей, с `dashboardFlowKind` —
 * только этот вид потока без «нераспределённого». `currency` — только валюта операции.
 */
final readonly class CashflowReportRowFilter
{
    /**
     * @param list<string>|null $projectDirectionIds
     * @param list<string>|null $responsibilityCenterIds
     */
    public function __construct(
        public bool $dashboardScope = false,
        public ?string $dashboardCurrency = null,
        public ?CashflowFlowKind $dashboardFlowKind = null,
        public ?string $currency = null,
        public ?array $projectDirectionIds = null,
        public ?array $responsibilityCenterIds = null,
        public ?string $responsibilityCenterId = null,
    ) {
    }
}
