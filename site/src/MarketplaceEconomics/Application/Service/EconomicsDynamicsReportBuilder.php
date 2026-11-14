<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\Service;

use App\MarketplaceEconomics\Application\DTO\EconomicsDynamicsReport;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsFilter;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsRow;
use App\MarketplaceEconomics\Domain\SkuMetrics;
use App\MarketplaceEconomics\Infrastructure\Query\SkuEconomicsQuery;

/**
 * Отчёт R2 «Динамика»: показатели по дням / неделям / месяцам. Набор SKU при фильтре по тегам
 * и поиску — тот же, что у R1 за период, поэтому итог R2 = итог R1.
 */
final readonly class EconomicsDynamicsReportBuilder
{
    public function __construct(
        private SkuEconomicsQuery $query,
        private SkuEconomicsReportBuilder $skuReports,
    ) {
    }

    public function build(string $companyId, SkuEconomicsFilter $filter, string $step): EconomicsDynamicsReport
    {
        if (!\in_array($step, EconomicsDynamicsReport::STEPS, true)) {
            $step = 'day';
        }
        $listingIds = $filter->filtersListings()
            ? array_map(static fn (SkuEconomicsRow $row): string => $row->listingId, $this->skuReports->build($companyId, $filter)->rows)
            : null;
        $byStep = $this->query->byStep($companyId, $filter->from, $filter->to, $step, $filter->connectionRef, $listingIds);

        $steps = [];
        $total = new SkuMetrics();
        $start = match ($step) {
            'week' => $filter->from->modify('monday this week'),
            'month' => $filter->from->modify('first day of this month'),
            default => $filter->from,
        };
        for ($cursor = $start; $cursor <= $filter->to; $cursor = $cursor->modify('+1 '.$step)) {
            $metrics = $byStep[$cursor->format('Y-m-d')] ?? new SkuMetrics();
            $total->merge($metrics);
            $end = $cursor->modify('+1 '.$step)->modify('-1 day');
            $steps[] = ['from' => max($cursor, $filter->from), 'to' => min($end, $filter->to), 'metrics' => $metrics];
        }

        return new EconomicsDynamicsReport($filter->from, $filter->to, $step, $steps, $total,
            $this->query->coverage($companyId, $filter->from, $filter->to)['connections']);
    }
}
