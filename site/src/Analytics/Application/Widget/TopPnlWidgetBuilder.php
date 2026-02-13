<?php

namespace App\Analytics\Application\Widget;

use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use App\Entity\PLCategory;
use App\Repository\PLCategoryRepository;
use App\Repository\PLDailyTotalRepository;

final readonly class TopPnlWidgetBuilder
{
    private const COVERAGE_TARGET = 0.8;
    private const MAX_ITEMS = 8;
    private const TREND_FLAT_THRESHOLD_PCT = 0.5;
    private const VAT_MODE = 'exclude';

    public function __construct(
        private PLDailyTotalRepository $plDailyTotalRepository,
        private PLCategoryRepository $plCategoryRepository,
        private ParetoTopItemsBuilder $paretoTopItemsBuilder,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(Company $company, Period $period): array
    {
        if (!$this->hasRegistryRows($company, $period)) {
            return $this->emptyWidget();
        }

        $categories = $this->plCategoryRepository->findBy(['company' => $company]);
        $expenseCategoriesById = $this->indexExpenseCategories($categories);

        $currentSums = $this->sumExpenseByCategory($company, $period, $expenseCategoriesById);
        if ([] === $currentSums) {
            return $this->emptyWidget();
        }

        $previousSums = $this->sumExpenseByCategory($company, $period->prevPeriod(), $expenseCategoriesById);

        $total = array_sum($currentSums);
        arsort($currentSums);

        $rows = [];
        foreach ($currentSums as $categoryId => $sum) {
            $prevSum = $previousSums[$categoryId] ?? 0.0;
            $deltaAbs = round($sum - $prevSum, 2);
            $deltaPct = round(($deltaAbs / max(abs($prevSum), 1.0)) * 100, 2);

            $rows[] = [
                'category_id' => $categoryId,
                'category_name' => (string) ($expenseCategoriesById[$categoryId]?->getName() ?? ''),
                'sum' => round($sum, 2),
                'share' => $total > 0.0 ? round($sum / $total, 4) : 0.0,
                'prev_sum' => round($prevSum, 2),
                'delta_abs' => $deltaAbs,
                'delta_pct' => $deltaPct,
                'trend' => $this->resolveTrend($deltaPct),
                'drilldown' => [
                    'key' => 'pl.documents',
                    'params' => [
                        'from' => $period->getFrom()->format('Y-m-d'),
                        'to' => $period->getTo()->format('Y-m-d'),
                        'type' => 'expense',
                        'category_id' => $categoryId,
                        'vat_mode' => self::VAT_MODE,
                    ],
                ],
            ];
        }

        $pareto = $this->paretoTopItemsBuilder->split($rows, $total, self::COVERAGE_TARGET, self::MAX_ITEMS);

        return [
            'coverage_target' => self::COVERAGE_TARGET,
            'max_items' => self::MAX_ITEMS,
            'items' => $pareto['items'],
            'other' => $pareto['other'],
        ];
    }

    private function hasRegistryRows(Company $company, Period $period): bool
    {
        $count = $this->plDailyTotalRepository->createQueryBuilder('dt')
            ->select('COUNT(dt.id)')
            ->andWhere('dt.company = :company')
            ->andWhere('dt.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $period->getFrom())
            ->setParameter('to', $period->getTo())
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * @param list<PLCategory> $categories
     *
     * @return array<string,PLCategory>
     */
    private function indexExpenseCategories(array $categories): array
    {
        $map = [];
        foreach ($categories as $category) {
            if (!$category->isExpenseRoot()) {
                continue;
            }

            $map[(string) $category->getId()] = $category;
        }

        return $map;
    }

    /**
     * @param array<string,PLCategory> $expenseCategoriesById
     *
     * @return array<string,float>
     */
    private function sumExpenseByCategory(Company $company, Period $period, array $expenseCategoriesById): array
    {
        if ([] === $expenseCategoriesById) {
            return [];
        }

        $rows = $this->plDailyTotalRepository->createQueryBuilder('dt')
            ->select('IDENTITY(dt.plCategory) as categoryId, COALESCE(SUM(dt.amountExpense), 0) as expenseSum')
            ->andWhere('dt.company = :company')
            ->andWhere('dt.date BETWEEN :from AND :to')
            ->andWhere('dt.plCategory IS NOT NULL')
            ->groupBy('dt.plCategory')
            ->setParameter('company', $company)
            ->setParameter('from', $period->getFrom())
            ->setParameter('to', $period->getTo())
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $categoryId = (string) ($row['categoryId'] ?? '');
            if ('' === $categoryId || !isset($expenseCategoriesById[$categoryId])) {
                continue;
            }

            $sum = round(abs((float) ($row['expenseSum'] ?? 0.0)), 2);
            if ($sum <= 0.0) {
                continue;
            }

            $result[$categoryId] = $sum;
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyWidget(): array
    {
        return [
            'coverage_target' => self::COVERAGE_TARGET,
            'max_items' => self::MAX_ITEMS,
            'items' => [],
            'other' => [
                'label' => 'Прочее',
                'sum' => 0.0,
                'share' => 0.0,
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
