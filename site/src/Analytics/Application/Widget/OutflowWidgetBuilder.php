<?php

declare(strict_types=1);

namespace App\Analytics\Application\Widget;

use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Domain\Period;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;

final readonly class OutflowWidgetBuilder
{
    public function __construct(
        private CashTransactionRepository $cashTransactionRepository,
        private DrilldownBuilder $drilldownBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $inflowWidget
     *
     * @return array<string, mixed>
     */
    public function build(Company $company, Period $period, array $inflowWidget, FiatCurrency $cashCurrency): array
    {
        $outflowSum = $this->cashTransactionRepository->sumOutflowExcludeTransfers($company, $period->getFrom(), $period->getTo(), $cashCurrency->value);

        $prevPeriod = $period->prevPeriod();
        $prevOutflowSum = $this->cashTransactionRepository->sumOutflowExcludeTransfers($company, $prevPeriod->getFrom(), $prevPeriod->getTo(), $cashCurrency->value);

        $capexAbs = $this->cashTransactionRepository->sumCapexOutflowExcludeTransfers($company, $period->getFrom(), $period->getTo(), $cashCurrency->value);

        $dailyRows = $this->cashTransactionRepository->sumOutflowByDayExcludeTransfers($company, $period->getFrom(), $period->getTo(), $cashCurrency->value);
        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $dailyMap[$row['date']] = (float) $row['value'];
        }

        $series = [];
        $cursor = $period->getFrom();
        while ($cursor <= $period->getTo()) {
            $date = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'value_abs' => $dailyMap[$date] ?? 0.0,
            ];

            $cursor = $cursor->modify('+1 day');
        }

        $deltaAbs = round($outflowSum - $prevOutflowSum, 2);
        $deltaPct = 0.0;
        if (0.0 !== $prevOutflowSum) {
            $deltaPct = round((($outflowSum - $prevOutflowSum) / $prevOutflowSum) * 100, 2);
        }

        $inflowSum = (float) ($inflowWidget['sum'] ?? 0.0);

        return [
            'sum_abs' => $outflowSum,
            'avg_daily' => round($outflowSum / $period->days(), 2),
            'delta_abs' => $deltaAbs,
            'delta_pct' => $deltaPct,
            'ratio_to_inflow' => round($outflowSum / max($inflowSum, 1.0), 4),
            'capex_abs' => $capexAbs,
            'series' => $series,
            'drilldown' => $this->drilldownBuilder->cashTransactions([
                'from' => $period->getFrom()->format('Y-m-d'),
                'to' => $period->getTo()->format('Y-m-d'),
                'direction' => 'out',
                'exclude_transfers' => true,
                'currency' => $cashCurrency->value,
            ]),
            'capex_drilldown' => $this->drilldownBuilder->cashTransactions([
                'from' => $period->getFrom()->format('Y-m-d'),
                'to' => $period->getTo()->format('Y-m-d'),
                'direction' => 'out',
                'exclude_transfers' => true,
                'system_code' => 'CAPEX',
                'currency' => $cashCurrency->value,
            ]),
        ];
    }
}
