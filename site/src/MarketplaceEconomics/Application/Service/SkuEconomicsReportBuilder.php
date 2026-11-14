<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\Service;

use App\Marketplace\Facade\ListingTagFacade;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsFilter;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsReport;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsRow;
use App\MarketplaceEconomics\Domain\SkuMetrics;
use App\MarketplaceEconomics\Infrastructure\Query\SkuEconomicsQuery;

/**
 * Отчёт R1 из витрины: текущий и прошлый период той же длины, названия листингов, фильтр по тегам
 * и поиску, сортировка. Итог без фильтра = кабинет (SKU + нераспределённое); с фильтром — Σ строк.
 */
final readonly class SkuEconomicsReportBuilder
{
    public function __construct(
        private SkuEconomicsQuery $query,
        private MarketplaceFacade $marketplace,
        private ListingTagFacade $tags,
    ) {
    }

    public function build(string $companyId, SkuEconomicsFilter $filter): SkuEconomicsReport
    {
        $days = (int) $filter->from->diff($filter->to)->days + 1;
        $previousTo = $filter->from->modify('-1 day');
        $previousFrom = $previousTo->modify(sprintf('-%d days', $days - 1));

        $current = $this->query->byListing($companyId, $filter->from, $filter->to, $filter->connectionRef);
        $previous = $this->query->byListing($companyId, $previousFrom, $previousTo, $filter->connectionRef);
        $unallocated = $current[''] ?? new SkuMetrics();
        $previousUnallocated = $previous[''] ?? new SkuMetrics();
        unset($current[''], $previous['']);

        $listingIds = array_keys($current);
        $meta = $this->marketplace->getListingsMetaByIds($companyId, array_map('strval', $listingIds));
        $tagged = [] === $filter->tagIds ? null : array_flip($this->tags->listingIdsByTags($companyId, $filter->tagIds));
        $needle = mb_strtolower(trim($filter->search));

        $rows = [];
        foreach ($current as $listingId => $metrics) {
            $listingId = (string) $listingId;
            $item = $meta[$listingId] ?? null;
            if (null !== $tagged && !isset($tagged[$listingId])) {
                continue;
            }
            if ('' !== $needle && !str_contains(mb_strtolower(implode(' ', [$item?->sku, $item?->title, $item?->supplierSku])), $needle)) {
                continue;
            }
            $rows[] = new SkuEconomicsRow($listingId, $item->sku ?? '—', $item?->title, $item?->supplierSku, $metrics, $previous[$listingId] ?? new SkuMetrics());
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

        return new SkuEconomicsReport(
            $filter->from, $filter->to, $previousFrom, $previousTo, $rows,
            $filter->filtersListings() ? null : $unallocated,
            $total, $previousTotal, $coverage['connections'], $coverage['days'], $coverage['daysWithoutCostPrice'],
        );
    }
}
