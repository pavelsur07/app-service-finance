<?php

namespace App\Analytics\Application\Widget;

use App\Analytics\Domain\Period;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;

final readonly class CashflowSplitWidgetBuilder
{
    public function __construct(
        private CashTransactionRepository $cashTransactionRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, Period $period): array
    {
        $prevPeriod = $period->prevPeriod();

        $netByKind = $this->cashTransactionRepository->sumNetByFlowKindExcludeTransfers($company, $period->getFrom(), $period->getTo());
        $prevNetByKind = $this->cashTransactionRepository->sumNetByFlowKindExcludeTransfers($company, $prevPeriod->getFrom(), $prevPeriod->getTo());

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
            'drilldown' => [
                'key' => 'cash.transactions',
                'params' => [
                    'from' => $period->getFrom()->format('Y-m-d'),
                    'to' => $period->getTo()->format('Y-m-d'),
                    'exclude_transfers' => true,
                ],
            ],
            'drilldowns_by_kind' => [
                CashflowFlowKind::OPERATING->value => $this->buildDrilldownByKind(CashflowFlowKind::OPERATING, $period),
                CashflowFlowKind::INVESTING->value => $this->buildDrilldownByKind(CashflowFlowKind::INVESTING, $period),
                CashflowFlowKind::FINANCING->value => $this->buildDrilldownByKind(CashflowFlowKind::FINANCING, $period),
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
    private function buildDrilldownByKind(CashflowFlowKind $kind, Period $period): array
    {
        return [
            'key' => 'cash.transactions',
            'params' => [
                'from' => $period->getFrom()->format('Y-m-d'),
                'to' => $period->getTo()->format('Y-m-d'),
                'exclude_transfers' => true,
                'flow_kind' => $kind->value,
            ],
        ];
    }
}
