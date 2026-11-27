<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\Service;

use App\Marketplace\Facade\ListingCategoryFacadeInterface;
use App\Marketplace\Facade\ListingTagFacade;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsCategoryGroup;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsFilter;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsReport;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsRow;
use App\MarketplaceEconomics\Domain\SkuMetrics;
use App\MarketplaceEconomics\Infrastructure\Query\SkuEconomicsQuery;
use App\Shared\Domain\AbcClass;
use App\Shared\Domain\AbcClassificationPolicy;

/**
 * Отчёт R1 из витрины: текущий и прошлый период той же длины, названия листингов, фильтр по тегам,
 * категории и поиску, сортировка. Итог без фильтра = кабинет (SKU + нераспределённое); с фильтром — Σ строк.
 * Режим «По категориям» группирует те же строки по категории листинга (определяется при чтении).
 */
final readonly class SkuEconomicsReportBuilder
{
    public function __construct(
        private SkuEconomicsQuery $query,
        private MarketplaceFacade $marketplace,
        private ListingTagFacade $tags,
        private ListingCategoryFacadeInterface $categories,
        private VatRatesByMonth $vatRates,
        private AbcClassificationPolicy $abc,
    ) {
    }

    public function build(string $companyId, SkuEconomicsFilter $filter): SkuEconomicsReport
    {
        $days = (int) $filter->from->diff($filter->to)->days + 1;
        $previousTo = $filter->from->modify('-1 day');
        $previousFrom = $previousTo->modify(sprintf('-%d days', $days - 1));

        $current = $this->query->byListing($companyId, $filter->from, $filter->to, $filter->connectionRef, $filter->channel, $this->vatRates->rates($companyId, $filter->from, $filter->to), $filter->allocated);
        $previous = $this->query->byListing($companyId, $previousFrom, $previousTo, $filter->connectionRef, $filter->channel, $this->vatRates->rates($companyId, $previousFrom, $previousTo), $filter->allocated);
        $unallocated = $current[''] ?? new SkuMetrics();
        $previousUnallocated = $previous[''] ?? new SkuMetrics();
        unset($current[''], $previous['']);

        $listingIds = array_keys($current);
        $meta = $this->marketplace->getListingsMetaByIds($companyId, array_map('strval', $listingIds));
        $tagged = [] === $filter->tagIds ? null : array_flip($this->tags->listingIdsByTags($companyId, $filter->tagIds));
        $needle = mb_strtolower(trim($filter->search));
        $needCategories = null !== $filter->category || $filter->byCategory;
        $categoryOf = $needCategories ? $this->categories->categoriesForListings($companyId, array_map('strval', $listingIds)) : [];

        $selected = $current;
        if (null !== $tagged) {
            $selected = array_intersect_key($selected, $tagged);
        }
        if (null !== $filter->category) {
            $selected = array_filter($selected, static fn (string $id): bool => ($categoryOf[$id] ?? SkuEconomicsFilter::CATEGORY_NONE) === $filter->category, \ARRAY_FILTER_USE_KEY);
        }

        // Поиск и пагинация меняют видимые строки, но сохраняют класс в выбранном наборе.
        $abcRevenue = $this->abc->classifyMinor(array_map(static fn (SkuMetrics $m): int => $m->revenue, $selected), AbcClass::C);
        $abcProfit = $filter->allocated ? $this->abc->classifyMinor(array_map(static fn (SkuMetrics $m): int => $m->full(), $selected), AbcClass::D) : [];

        $rows = [];
        foreach ($selected as $listingId => $metrics) {
            $listingId = (string) $listingId;
            $item = $meta[$listingId] ?? null;
            if ('' !== $needle && !str_contains(mb_strtolower(implode(' ', [$item?->sku, $item?->title, $item?->supplierSku])), $needle)) {
                continue;
            }
            $rows[] = new SkuEconomicsRow($listingId, $item->sku ?? '—', $item?->title, $item?->supplierSku, $metrics, $previous[$listingId] ?? new SkuMetrics(), $abcRevenue[$listingId], $abcProfit[$listingId] ?? null, $item?->marketplace);
        }

        $value = static fn (SkuMetrics $m): int => match ($filter->sort) {
            'revenue' => $m->revenue,
            'gross' => $m->gross(),
            'md1' => $m->md1(),
            'full' => $m->full(),
            'ads' => -$m->ads,
            'sales' => $m->salesQty,
            default => $m->md2(),
        };
        usort($rows, static fn (SkuEconomicsRow $a, SkuEconomicsRow $b): int => ($filter->desc ? -1 : 1) * ($value($a->current) <=> $value($b->current)) ?: strcmp($a->listingId, $b->listingId));

        $total = new SkuMetrics();
        $previousTotal = new SkuMetrics();
        foreach ($rows as $row) {
            $total->merge($row->current);
            $previousTotal->merge($row->previous);
        }
        if (!$filter->filtersListings()) {
            // строки прошлого периода без продаж в текущем — тоже часть итога кабинета
            foreach (array_diff_key($previous, $current) as $metrics) {
                $previousTotal->merge($metrics);
            }
            $total->merge($unallocated);
            $previousTotal->merge($previousUnallocated);
        }

        $coverage = $this->query->coverage($companyId, $filter->from, $filter->to);
        $groups = $filter->byCategory ? $this->groups($companyId, $rows, $categoryOf, $value, $filter->desc) : null;
        $share = null;
        if (null !== $groups) {
            $all = array_sum(array_map(static fn (SkuEconomicsRow $r): int => $r->current->revenue, $rows));
            $categorized = array_sum(array_map(static fn (SkuEconomicsCategoryGroup $g): int => null === $g->categoryId ? 0 : $g->current->revenue, $groups));
            $share = 0 === $all ? null : round($categorized * 100 / $all, 1);
        }

        return new SkuEconomicsReport(
            $filter->from, $filter->to, $previousFrom, $previousTo, $rows,
            $filter->filtersListings() ? null : $unallocated,
            $total, $previousTotal, $coverage['connections'], $coverage['days'], $coverage['daysWithoutCostPrice'], $coverage['channels'],
            $groups, $share,
        );
    }

    /**
     * Строки (уже отсортированные) раскладываются по категориям; группы сортируются по той же
     * колонке, «Без категории» — последней. Показатели группы — сумма строк, проценты считает SkuMetrics.
     *
     * @param list<SkuEconomicsRow> $rows
     * @param array<string, string> $categoryOf listingId → categoryId
     * @param callable(SkuMetrics): int $value
     *
     * @return list<SkuEconomicsCategoryGroup>
     */
    private function groups(string $companyId, array $rows, array $categoryOf, callable $value, bool $desc): array
    {
        $names = [];
        foreach ($this->categories->list($companyId) as $category) {
            $names[$category->id] = $category->name;
        }

        /** @var array<string, array{current: SkuMetrics, previous: SkuMetrics, rows: list<SkuEconomicsRow>}> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $key = $categoryOf[$row->listingId] ?? '';
            $buckets[$key] ??= ['current' => new SkuMetrics(), 'previous' => new SkuMetrics(), 'rows' => []];
            $buckets[$key]['current']->merge($row->current);
            $buckets[$key]['previous']->merge($row->previous);
            $buckets[$key]['rows'][] = $row;
        }

        $groups = [];
        foreach ($buckets as $key => $bucket) {
            $key = (string) $key;
            $groups[] = new SkuEconomicsCategoryGroup(
                '' === $key ? null : $key,
                '' === $key ? 'Без категории' : ($names[$key] ?? 'Без категории'),
                $bucket['current'],
                $bucket['previous'],
                $bucket['rows'],
            );
        }
        usort($groups, static fn (SkuEconomicsCategoryGroup $a, SkuEconomicsCategoryGroup $b): int => (null === $a->categoryId) <=> (null === $b->categoryId)
            ?: ($desc ? -1 : 1) * ($value($a->current) <=> $value($b->current))
            ?: strcmp($a->name, $b->name));

        return $groups;
    }
}
