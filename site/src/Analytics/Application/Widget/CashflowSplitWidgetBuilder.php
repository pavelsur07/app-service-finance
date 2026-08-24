<?php

declare(strict_types=1);

namespace App\Analytics\Application\Widget;

use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Domain\Period;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;

final readonly class CashflowSplitWidgetBuilder
{
    public function __construct(
        private CashTransactionRepository $cashTransactionRepository,
        private DrilldownBuilder $drilldownBuilder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, Period $period, FiatCurrency $cashCurrency): array
    {
        $prevPeriod = $period->prevPeriod();

        $netByKind = $this->cashTransactionRepository->sumNetByFlowKindExcludeTransfers($company, $period->getFrom(), $period->getTo(), $cashCurrency->value);
        $prevNetByKind = $this->cashTransactionRepository->sumNetByFlowKindExcludeTransfers($company, $prevPeriod->getFrom(), $prevPeriod->getTo(), $cashCurrency->value);

        return [
            'operating' => $this->buildKindPayload(CashflowFlowKind::OPERATING, $netByKind, $prevNetByKind),
            'investing' => $this->buildKindPayload(CashflowFlowKind::INVESTING, $netByKind, $prevNetByKind),
            'financing' => $this->buildKindPayload(CashflowFlowKind::FINANCING, $netByKind, $prevNetByKind),
            'total' => [
                'net' => round(
                    ($netByKind[CashflowFlowKind::OPERATING->value] ?? 0.0)
                    + ($netByKind[CashflowFlowKind::INVESTING->value] ?? 0.0)
                    + ($netByKind[CashflowFlowKind::FINANCING->value] ?? 0.0),
                    2,
                ),
            ],
            'drilldown' => $this->drilldownBuilder->cashTransactions([
                'from' => $period->getFrom()->format('Y-m-d'),
                'to' => $period->getTo()->format('Y-m-d'),
                'exclude_transfers' => true,
                'currency' => $cashCurrency->value,
            ]),
            'drilldowns_by_kind' => [
                CashflowFlowKind::OPERATING->value => $this->buildDrilldownByKind(CashflowFlowKind::OPERATING, $period, $cashCurrency),
                CashflowFlowKind::INVESTING->value => $this->buildDrilldownByKind(CashflowFlowKind::INVESTING, $period, $cashCurrency),
                CashflowFlowKind::FINANCING->value => $this->buildDrilldownByKind(CashflowFlowKind::FINANCING, $period, $cashCurrency),
            ],
        ];
    }

    /**
     * @param array<string, float> $netByKind
     * @param array<string, float> $prevNetByKind
     *
     * @return array{net: float, delta_abs: float, delta_pct: float}
     */
    private function buildKindPayload(CashflowFlowKind $kind, array $netByKind, array $prevNetByKind): array
    {
        $net = round((float) ($netByKind[$kind->value] ?? 0.0), 2);
        $netPrev = round((float) ($prevNetByKind[$kind->value] ?? 0.0), 2);

        return [
            'net' => $net,
            'delta_abs' => round($net - $netPrev, 2),
            'delta_pct' => round((($net - $netPrev) / max(abs($netPrev), 1.0)) * 100, 2),
        ];
    }

    /**
     * @return array{key: string, params: array<string, mixed>}
     */
    private function buildDrilldownByKind(CashflowFlowKind $kind, Period $period, FiatCurrency $cashCurrency): array
    {
        return $this->drilldownBuilder->cashTransactions([
            'from' => $period->getFrom()->format('Y-m-d'),
            'to' => $period->getTo()->format('Y-m-d'),
            'exclude_transfers' => true,
            'flow_kind' => $kind->value,
            'currency' => $cashCurrency->value,
        ]);
    }
}
