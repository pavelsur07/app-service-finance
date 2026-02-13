<?php

namespace App\Analytics\Application\Widget;

use App\Analytics\Domain\Period;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;

final readonly class TopCashWidgetBuilder
{
    private const COVERAGE_TARGET = 0.8;
    private const MAX_ITEMS = 8;
    private const TREND_FLAT_THRESHOLD_PCT = 0.5;

    public function __construct(
        private CashTransactionRepository $cashTransactionRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, Period $period): array
    {
        $rows = $this->cashTransactionRepository->sumOutflowByCategoryExcludeTransfers($company, $period->getFrom(), $period->getTo());

        $prevPeriod = $period->prevPeriod();
        $prevRows = $this->cashTransactionRepository->sumOutflowByCategoryExcludeTransfers($company, $prevPeriod->getFrom(), $prevPeriod->getTo());

        $prevSums = [];
        foreach ($prevRows as $row) {
            $categoryId = (string) ($row['categoryId'] ?? '');
            if ('' === $categoryId) {
                continue;
            }

            $prevSums[$categoryId] = (float) ($row['sumAbs'] ?? 0.0);
        }

        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row['sumAbs'] ?? 0.0);
        }

        usort($rows, static fn (array $left, array $right): int => ((float) ($right['sumAbs'] ?? 0.0)) <=> ((float) ($left['sumAbs'] ?? 0.0)));

        $items = [];
        $otherSum = 0.0;
        $cumulativeSum = 0.0;

        foreach ($rows as $row) {
            $sumAbs = (float) ($row['sumAbs'] ?? 0.0);
            $categoryId = (string) ($row['categoryId'] ?? '');
            $prevSumAbs = $prevSums[$categoryId] ?? 0.0;

            if (\count($items) < self::MAX_ITEMS && ($total <= 0.0 || ($cumulativeSum / $total) < self::COVERAGE_TARGET)) {
                $share = $total > 0.0 ? round($sumAbs / $total, 4) : 0.0;
                $deltaAbs = round($sumAbs - $prevSumAbs, 2);
                $deltaPct = 0.0;
                if (0.0 !== $prevSumAbs) {
                    $deltaPct = round((($sumAbs - $prevSumAbs) / $prevSumAbs) * 100, 2);
                }

                $items[] = [
                    'category_id' => $categoryId,
                    'category_name' => (string) ($row['categoryName'] ?? ''),
                    'sum_abs' => round($sumAbs, 2),
                    'share' => $share,
                    'prev_sum_abs' => round($prevSumAbs, 2),
                    'delta_abs' => $deltaAbs,
                    'delta_pct' => $deltaPct,
                    'trend' => $this->resolveTrend($deltaPct),
                    'drilldown' => [
                        'key' => 'cash.transactions',
                        'params' => [
                            'from' => $period->getFrom()->format('Y-m-d'),
                            'to' => $period->getTo()->format('Y-m-d'),
                            'direction' => 'out',
                            'exclude_transfers' => true,
                            'category_id' => $categoryId,
                        ],
                    ],
                ];

                $cumulativeSum += $sumAbs;

                continue;
            }

            $otherSum += $sumAbs;
        }

        return [
            'coverage_target' => self::COVERAGE_TARGET,
            'max_items' => self::MAX_ITEMS,
            'items' => $items,
            'other' => [
                'label' => 'Прочее',
                'sum_abs' => round($otherSum, 2),
                'share' => $total > 0.0 ? round($otherSum / $total, 4) : 0.0,
            ],
        ];
    }

    private function resolveTrend(float $deltaPct): string
    {
        if (abs($deltaPct) < self::TREND_FLAT_THRESHOLD_PCT) {
            return 'flat';
        }

        return $deltaPct > 0.0 ? 'up' : 'down';
    }
}
