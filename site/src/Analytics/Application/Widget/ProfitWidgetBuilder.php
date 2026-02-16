<?php

namespace App\Analytics\Application\Widget;

use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use App\Entity\PLCategory;
use App\Enum\PLExpenseType;
use App\Finance\Report\PlReportGridBuilder;
use App\Repository\PLCategoryRepository;

final readonly class ProfitWidgetBuilder
{
    public function __construct(
        private PlReportGridBuilder $plReportGridBuilder,
        private PLCategoryRepository $plCategoryRepository,
        private DrilldownBuilder $drilldownBuilder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, Period $period): array
    {
        $current = $this->collectProfitData($company, $period);
        $previous = $this->collectProfitData($company, $period->prevPeriod());

        return [
            'revenue' => $current['revenue'],
            'variable_costs' => $current['variable_costs'],
            'opex' => $current['opex'],
            'gross_profit' => $current['gross_profit'],
            'ebitda' => $current['ebitda'],
            'margin_pct' => $current['margin_pct'],
            'delta' => [
                'ebitda_abs' => round($current['ebitda'] - $previous['ebitda'], 2),
                'margin_pp' => round($current['margin_pct'] - $previous['margin_pct'], 2),
            ],
            'drilldowns' => [
                'revenue' => $this->drilldownBuilder->plDocuments([
                    'from' => $period->getFrom()->format('Y-m-d'),
                    'to' => $period->getTo()->format('Y-m-d'),
                    'type' => 'revenue',
                ]),
                'variable_costs' => $this->drilldownBuilder->plDocuments([
                    'from' => $period->getFrom()->format('Y-m-d'),
                    'to' => $period->getTo()->format('Y-m-d'),
                    'type' => 'variable',
                ]),
                'opex' => $this->drilldownBuilder->plDocuments([
                    'from' => $period->getFrom()->format('Y-m-d'),
                    'to' => $period->getTo()->format('Y-m-d'),
                    'type' => 'opex',
                ]),
                'report' => $this->drilldownBuilder->plReport([
                    'from' => $period->getFrom()->format('Y-m-d'),
                    'to' => $period->getTo()->format('Y-m-d'),
                ]),
            ],
        ];
    }

    /**
     * @return array{revenue: float, variable_costs: float, opex: float, gross_profit: float, ebitda: float, margin_pct: float}
     */
    private function collectProfitData(Company $company, Period $period): array
    {
        $map = $this->resolveCategoryMap($company);
        $totalData = $this->plReportGridBuilder->build($company, $period->getFrom(), $period->getTo(), 'month');
        $rawValues = $totalData['rawValues'] ?? [];

        $revenue = $this->sumByCategoryIds($map['revenue'], $rawValues);
        $variableCosts = $this->sumByCategoryIds($map['variable'], $rawValues);
        $opex = $this->sumByCategoryIds($map['opex'], $rawValues);
        $grossProfit = round($revenue - $variableCosts, 2);
        $ebitda = round($grossProfit - $opex, 2);
        $marginPct = round(($ebitda / max($revenue, 1.0)) * 100, 2);

        return [
            'revenue' => $revenue,
            'variable_costs' => $variableCosts,
            'opex' => $opex,
            'gross_profit' => $grossProfit,
            'ebitda' => $ebitda,
            'margin_pct' => $marginPct,
        ];
    }

    /**
     * @return array{revenue: list<string>, variable: list<string>, opex: list<string>}
     */
    private function resolveCategoryMap(Company $company): array
    {
        $categories = $this->plCategoryRepository->findBy(['company' => $company]);

        $revenue = [];
        $variable = [];
        $opex = [];

        foreach ($categories as $category) {
            if (!$category instanceof PLCategory) {
                continue;
            }

            if (null === $category->getParent() && $category->isIncomeRoot()) {
                $revenue[] = (string) $category->getId();
            }

            if (!$category->isExpenseRoot()) {
                continue;
            }

            if (PLExpenseType::VARIABLE === $category->getExpenseType()) {
                $variable[] = (string) $category->getId();
                continue;
            }

            if (PLExpenseType::OPEX === $category->getExpenseType()) {
                $opex[] = (string) $category->getId();
            }
        }

        return [
            'revenue' => array_values(array_unique($revenue)),
            'variable' => array_values(array_unique($variable)),
            'opex' => array_values(array_unique($opex)),
        ];
    }

    /**
     * @param list<string> $categoryIds
     * @param array<string,array<string,float>> $rawValues
     */
    private function sumByCategoryIds(array $categoryIds, array $rawValues): float
    {
        $sum = 0.0;
        foreach ($categoryIds as $categoryId) {
            foreach (($rawValues[$categoryId] ?? []) as $value) {
                $sum += (float) $value;
            }
        }

        return round($sum, 2);
    }
}
