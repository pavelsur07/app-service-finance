<?php

namespace App\Analytics\Application\Widget;

use App\Analytics\Api\Response\RevenueWidgetResponse;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use App\Entity\PLCategory;
use App\Finance\Report\PlReportGridBuilder;
use App\Finance\Report\PlReportPeriod;
use App\Repository\PLCategoryRepository;
use App\Repository\PLDailyTotalRepository;

final readonly class RevenueWidgetBuilder
{
    public function __construct(
        private PlReportGridBuilder $plReportGridBuilder,
        private PLCategoryRepository $plCategoryRepository,
        private PLDailyTotalRepository $plDailyTotalRepository,
    ) {
    }

    /**
     * @return array{widget: RevenueWidgetResponse, registryEmpty: bool}
     */
    public function build(Company $company, Period $period): array
    {
        $current = $this->collectRevenueData($company, $period);
        $previousPeriod = $period->prevPeriod();
        $previous = $this->collectRevenueData($company, $previousPeriod);

        $deltaAbs = (float) bcsub((string) $current['sum'], (string) $previous['sum'], 2);
        $deltaPct = 0.0;
        if (0.0 !== $previous['sum']) {
            $deltaPct = round((($current['sum'] - $previous['sum']) / $previous['sum']) * 100, 2);
        }

        return [
            'widget' => new RevenueWidgetResponse(
                sum: $current['sum'],
                deltaAbs: $deltaAbs,
                deltaPct: $deltaPct,
                series: $current['series'],
                drilldown: [
                    'key' => 'pl.documents',
                    'params' => [
                        'from' => $period->getFrom()->format('Y-m-d'),
                        'to' => $period->getTo()->format('Y-m-d'),
                        'type' => 'revenue',
                    ],
                ],
            ),
            'registryEmpty' => $current['registryEmpty'],
        ];
    }

    /**
     * @return array{sum: float,series:list<array{date:string,value:float}>,registryEmpty:bool}
     */
    private function collectRevenueData(Company $company, Period $period): array
    {
        $incomeRootIds = $this->resolveIncomeRootIds($company);
        $hasRegistryRows = $this->hasRegistryRows($company, $period);

        if ([] === $incomeRootIds || !$hasRegistryRows) {
            return ['sum' => 0.0, 'series' => [], 'registryEmpty' => true];
        }

        $totalData = $this->plReportGridBuilder->build($company, $period->getFrom(), $period->getTo(), 'month');
        $sum = $this->sumByRootIds($incomeRootIds, $totalData['rawValues'] ?? []);

        $dailyData = $this->plReportGridBuilder->build($company, $period->getFrom(), $period->getTo(), 'day');
        $series = $this->buildSeries($incomeRootIds, $dailyData['periods'] ?? [], $dailyData['rawValues'] ?? []);

        return ['sum' => $sum, 'series' => $series, 'registryEmpty' => false];
    }

    /** @return list<string> */
    private function resolveIncomeRootIds(Company $company): array
    {
        $categories = $this->plCategoryRepository->findBy(['company' => $company]);

        $ids = [];
        foreach ($categories as $category) {
            if (!$category instanceof PLCategory || null !== $category->getParent()) {
                continue;
            }

            if ($category->isIncomeRoot()) {
                $ids[] = $category->getId();
            }
        }

        return array_values(array_unique($ids));
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
     * @param list<string>                      $incomeRootIds
     * @param array<string,array<string,float>> $rawValues
     */
    private function sumByRootIds(array $incomeRootIds, array $rawValues): float
    {
        $sum = 0.0;
        foreach ($incomeRootIds as $categoryId) {
            $periodValues = $rawValues[$categoryId] ?? [];
            foreach ($periodValues as $value) {
                $sum += (float) $value;
            }
        }

        return round($sum, 2);
    }

    /**
     * @param list<string>                      $incomeRootIds
     * @param list<PlReportPeriod>              $periods
     * @param array<string,array<string,float>> $rawValues
     *
     * @return list<array{date:string,value:float}>
     */
    private function buildSeries(array $incomeRootIds, array $periods, array $rawValues): array
    {
        $series = [];
        foreach ($periods as $period) {
            $dayValue = 0.0;
            foreach ($incomeRootIds as $categoryId) {
                $dayValue += (float) ($rawValues[$categoryId][$period->id] ?? 0.0);
            }

            $series[] = [
                'date' => $period->from->format('Y-m-d'),
                'value' => round($dayValue, 2),
            ];
        }

        return $series;
    }
}
