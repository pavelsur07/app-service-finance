<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

use App\MarketplaceEconomics\Domain\SkuMetrics;

/**
 * Сводка недельного отчёта R3 (`REPORTS.md` §4) из отчёта R1 за неделю: итог против прошлой недели,
 * SKU с наибольшим ростом и падением СМ2, SKU в минусе по СМ2, SKU с ДРР выше порога.
 */
final readonly class WeeklyEconomicsSummary
{
    public const HIGH_DRR = 20.0;

    /**
     * @param list<array{sku: string, title: ?string, value: int, delta: int}> $growth
     * @param list<array{sku: string, title: ?string, value: int, delta: int}> $fall
     * @param list<array{sku: string, title: ?string, value: int, delta: int}> $worst
     */
    public function __construct(
        public SkuEconomicsReport $report,
        public SkuMetrics $total,
        public SkuMetrics $previousTotal,
        public int $md2Delta,
        public array $growth,
        public array $fall,
        public int $losses,
        public array $worst,
        public int $highDrr,
    ) {
    }

    public static function fromReport(SkuEconomicsReport $report, int $top = 5): self
    {
        $item = static fn (SkuEconomicsRow $r): array => ['sku' => $r->sku, 'title' => $r->title, 'value' => $r->current->md2(), 'delta' => $r->current->md2() - $r->previous->md2()];
        $rows = array_map($item, $report->rows);

        usort($rows, static fn (array $a, array $b): int => $b['delta'] <=> $a['delta'] ?: strcmp($a['sku'], $b['sku']));
        $growth = \array_slice(array_values(array_filter($rows, static fn (array $r): bool => $r['delta'] > 0)), 0, $top);
        $fall = \array_slice(array_reverse(array_values(array_filter($rows, static fn (array $r): bool => $r['delta'] < 0))), 0, $top);

        $losses = array_values(array_filter($rows, static fn (array $r): bool => $r['value'] < 0));
        usort($losses, static fn (array $a, array $b): int => $a['value'] <=> $b['value'] ?: strcmp($a['sku'], $b['sku']));

        $highDrr = \count(array_filter($report->rows, static fn (SkuEconomicsRow $r): bool => ($r->current->drr() ?? 0.0) > self::HIGH_DRR));

        return new self($report, $report->total, $report->previousTotal, $report->total->md2() - $report->previousTotal->md2(),
            $growth, $fall, \count($losses), \array_slice($losses, 0, $top), $highDrr);
    }
}
