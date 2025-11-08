<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\Repository\WildberriesRnpDailyRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final class WildberriesRnpReportService
{
    public function __construct(private readonly WildberriesRnpDailyRepository $repository)
    {
    }

    /**
     * @param array{sku?: list<string>, brand?: list<string>, category?: list<string>} $filters
     *
     * @return array<string, mixed>
     */
    public function buildReport(Company $company, DateTimeImmutable $from, DateTimeImmutable $to, array $filters = []): array
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        $normalizedFilters = $this->normalizeFilters($filters);

        $rows = $this->repository->findRangeGroupedDaySku($company, $from, $to, $normalizedFilters);
        $totals = $this->repository->sumRangeByCompany($company, $from, $to, $normalizedFilters);

        $items = [];
        foreach ($rows as $row) {
            $dateValue = $row['date'];
            $date = $dateValue instanceof \DateTimeInterface
                ? $dateValue
                : new DateTimeImmutable((string) $dateValue);

            $ordersCount = (int) $row['orders_count_spp'];
            $ordersSum = (int) $row['orders_sum_spp_minor'];
            $salesCount = (int) $row['sales_count_spp'];
            $salesSum = (int) $row['sales_sum_spp_minor'];
            $adCost = (int) $row['ad_cost_sum_minor'];
            $cogs = (int) $row['cogs_sum_spp_minor'];
            $gross = $salesSum - $cogs;
            $contribution = $gross - $adCost;

            $items[] = [
                'date' => $date->format('Y-m-d'),
                'sku' => (string) $row['sku'],
                'category' => $row['category'] !== null ? (string) $row['category'] : null,
                'brand' => $row['brand'] !== null ? (string) $row['brand'] : null,
                'orders_count_spp' => $ordersCount,
                'orders_sum_spp_minor' => $ordersSum,
                'sales_count_spp' => $salesCount,
                'sales_sum_spp_minor' => $salesSum,
                'ad_cost_sum_minor' => $adCost,
                'buyout_rate' => $this->formatRate($row['buyout_rate']),
                'cogs_sum_spp_minor' => $cogs,
                'gross_profit_minor' => $gross,
                'contribution_minor' => $contribution,
            ];
        }

        $totalSalesSum = $totals['sales_sum_spp_minor_total'] ?? 0;
        $totalCogs = $totals['cogs_sum_spp_minor_total'] ?? 0;
        $totalAd = $totals['ad_cost_sum_minor_total'] ?? 0;
        $totalGross = (int) $totalSalesSum - (int) $totalCogs;
        $totalContribution = $totalGross - (int) $totalAd;

        return [
            'meta' => [
                'companyId' => (string) $company->getId(),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'generatedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
                'currency' => 'RUB',
                'filters' => [
                    'sku' => $normalizedFilters['sku'],
                    'brand' => $normalizedFilters['brand'],
                    'category' => $normalizedFilters['category'],
                ],
            ],
            'items' => $items,
            'totals' => [
                'orders_count_spp' => (int) ($totals['orders_count_spp_total'] ?? 0),
                'orders_sum_spp_minor' => (int) ($totals['orders_sum_spp_minor_total'] ?? 0),
                'sales_count_spp' => (int) ($totals['sales_count_spp_total'] ?? 0),
                'sales_sum_spp_minor' => (int) $totalSalesSum,
                'ad_cost_sum_minor' => (int) $totalAd,
                'cogs_sum_spp_minor' => (int) $totalCogs,
                'buyout_rate_weighted' => $this->formatRate($totals['buyout_rate_weighted'] ?? '0'),
                'gross_profit_minor' => $totalGross,
                'contribution_minor' => $totalContribution,
            ],
        ];
    }

    /**
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public function resolvePeriod(string $period, ?DateTimeImmutable $anchor = null): array
    {
        $anchor = ($anchor ?? new DateTimeImmutable('today'))->setTime(0, 0);

        return match ($period) {
            'week' => $this->resolveWeekPeriod($anchor),
            'month' => $this->resolveMonthPeriod($anchor),
            'quarter' => $this->resolveQuarterPeriod($anchor),
            default => throw new InvalidArgumentException(sprintf('Unsupported period "%s"', $period)),
        };
    }

    /**
     * @param array{sku?: mixed, brand?: mixed, category?: mixed} $filters
     *
     * @return array{sku: list<string>, brand: list<string>, category: list<string>}
     */
    private function normalizeFilters(array $filters): array
    {
        $normalizeList = static function (mixed $value): array {
            if (!\is_array($value)) {
                return [];
            }

            $result = [];
            foreach ($value as $item) {
                if (\is_string($item) && $item !== '') {
                    $result[] = $item;
                }
            }

            return array_values(array_unique($result));
        };

        return [
            'sku' => $normalizeList($filters['sku'] ?? []),
            'brand' => $normalizeList($filters['brand'] ?? []),
            'category' => $normalizeList($filters['category'] ?? []),
        ];
    }

    private function formatRate(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    private function resolveWeekPeriod(DateTimeImmutable $anchor): array
    {
        $dayOfWeek = (int) $anchor->format('N');
        $start = $anchor->modify(sprintf('-%d days', $dayOfWeek - 1));
        $end = $start->modify('+6 days');

        return ['from' => $start, 'to' => $end];
    }

    /**
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    private function resolveMonthPeriod(DateTimeImmutable $anchor): array
    {
        $start = $anchor->modify('first day of this month');
        $end = $start->modify('last day of this month');

        return ['from' => $start, 'to' => $end];
    }

    /**
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    private function resolveQuarterPeriod(DateTimeImmutable $anchor): array
    {
        $year = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n');
        $quarterIndex = intdiv($month - 1, 3);
        $startMonth = $quarterIndex * 3 + 1;
        $start = $anchor->setDate($year, $startMonth, 1);
        $end = $start->modify('+3 months -1 day');

        return ['from' => $start, 'to' => $end];
    }
}
