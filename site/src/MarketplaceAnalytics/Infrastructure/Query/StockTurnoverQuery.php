<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use App\Inventory\Facade\InventoryFacade;
use App\Marketplace\Facade\ListingTagFacade;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Domain\Service\StockTurnoverPolicy;
use App\MarketplaceAnalytics\DTO\StockTurnoverFilter;
use App\MarketplaceAnalytics\DTO\StockTurnoverReport;
use App\MarketplaceAnalytics\DTO\StockTurnoverRow;
use App\MarketplaceAnalytics\DTO\StockTurnoverSummary;
use App\MarketplaceAnalytics\Enum\TurnoverBucket;

/**
 * Отчёт «Оборачиваемость»: где заморожены деньги. Собирается на лету из трёх Facade —
 * остатки на дату (Inventory), продажи за окно и себестоимость на дату (Marketplace).
 * Строка — листинг с остатком > 0; деньги = остаток × себестоимость; сводка — по всем
 * строкам фильтра, фильтр корзины применяется после неё.
 */
final readonly class StockTurnoverQuery
{
    public function __construct(
        private InventoryFacade $inventoryFacade,
        private MarketplaceFacade $marketplaceFacade,
        private ListingTagFacade $listingTagFacade,
        private StockTurnoverPolicy $policy,
    ) {
    }

    public function execute(StockTurnoverFilter $filter): StockTurnoverReport
    {
        $stock = $this->inventoryFacade->getStockQtyByListingOnReportDate(
            $filter->companyId,
            $filter->date,
            $filter->source,
            $filter->scheme,
        );

        $qtyByListingId = [];
        foreach ($stock->qtyByListingId as $listingId => $qty) {
            $rounded = (int) round($qty);
            if ($rounded > 0) {
                $qtyByListingId[(string) $listingId] = $rounded;
            }
        }

        if ([] !== $filter->tagIds && [] !== $qtyByListingId) {
            $tagged = array_fill_keys($this->listingTagFacade->listingIdsByTags($filter->companyId, $filter->tagIds), true);
            $qtyByListingId = array_intersect_key($qtyByListingId, $tagged);
        }

        $listingIds = array_keys($qtyByListingId);
        $sales = $this->marketplaceFacade->getSalesAggregatesByListing($filter->companyId, $filter->source, $filter->salesFrom(), $filter->date);
        $costPrices = [] === $listingIds ? [] : $this->marketplaceFacade->getCostPricesForListingsOnDate($filter->companyId, $listingIds, $filter->date);
        $meta = [] === $listingIds ? [] : $this->marketplaceFacade->getListingsMetaByIds($filter->companyId, $listingIds);

        $rows = [];
        foreach ($qtyByListingId as $listingId => $stockQty) {
            $salesQty = isset($sales[$listingId]) ? $sales[$listingId]->quantity : 0;
            $measure = $this->policy->measure($stockQty, $salesQty, $filter->periodDays);
            $costPrice = $costPrices[$listingId] ?? null;
            $listingMeta = $meta[$listingId] ?? null;

            $rows[] = new StockTurnoverRow(
                listingId: $listingId,
                title: $listingMeta?->title,
                sku: null !== $listingMeta ? $listingMeta->sku : '—',
                supplierSku: $listingMeta?->supplierSku,
                marketplace: null !== $listingMeta ? $listingMeta->marketplace : ($filter->source ?? ''),
                stockQty: $stockQty,
                salesQty: $salesQty,
                salesPerDay: $measure->salesPerDay,
                daysOfCover: $measure->daysOfCover,
                costPrice: $costPrice,
                stockValue: null === $costPrice ? null : bcmul((string) $stockQty, $costPrice, 2),
                bucket: $measure->bucket,
            );
        }

        usort($rows, static function (StockTurnoverRow $a, StockTurnoverRow $b): int {
            if (null === $a->stockValue || null === $b->stockValue) {
                // Без себестоимости — после всех, между собой по названию.
                if (null !== $a->stockValue || null !== $b->stockValue) {
                    return null === $a->stockValue ? 1 : -1;
                }

                return strcmp((string) $a->title, (string) $b->title);
            }

            return bccomp($b->stockValue, $a->stockValue, 2) ?: strcmp((string) $a->title, (string) $b->title);
        });

        $summary = $this->summarize($rows, $stock->snapshotDateBySource, $stock->staleSources);

        if (null !== $filter->bucket) {
            $bucket = $filter->bucket;
            $rows = array_values(array_filter($rows, static fn (StockTurnoverRow $row): bool => $row->bucket === $bucket));
        }

        return new StockTurnoverReport($rows, $summary);
    }

    /**
     * @param list<StockTurnoverRow> $rows
     * @param array<string, string> $snapshotDateBySource
     * @param array<string, string> $staleSources
     */
    private function summarize(array $rows, array $snapshotDateBySource, array $staleSources): StockTurnoverSummary
    {
        $valueByBucket = array_fill_keys(array_column(TurnoverBucket::cases(), 'value'), '0.00');
        $total = '0.00';
        $frozen = '0.00';
        $withoutCost = 0;
        $qtyWithoutCost = 0;

        foreach ($rows as $row) {
            if (null === $row->stockValue) {
                ++$withoutCost;
                $qtyWithoutCost += $row->stockQty;

                continue;
            }

            $total = bcadd($total, $row->stockValue, 2);
            $valueByBucket[$row->bucket->value] = bcadd($valueByBucket[$row->bucket->value], $row->stockValue, 2);
            if ($row->bucket->isFrozen()) {
                $frozen = bcadd($frozen, $row->stockValue, 2);
            }
        }

        $share = 0 === bccomp($total, '0.00', 2)
            ? null
            : round((float) bcdiv(bcmul($frozen, '100', 4), $total, 4), 2);

        return new StockTurnoverSummary(
            totalValue: $total,
            frozenValue: $frozen,
            frozenShare: $share,
            valueByBucket: $valueByBucket,
            listingsWithoutCostPrice: $withoutCost,
            qtyWithoutCostPrice: $qtyWithoutCost,
            listingsTotal: count($rows),
            snapshotDateBySource: $snapshotDateBySource,
            staleSources: $staleSources,
        );
    }
}
