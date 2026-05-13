<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\DTO\ListingCostCategoryAggregateDTO;
use App\Marketplace\DTO\ListingMetaDTO;
use App\Marketplace\DTO\ListingReturnAggregateDTO;
use App\Marketplace\DTO\ListingSalesAggregateDTO;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
use App\MarketplaceAnalytics\Application\Service\MarketplaceCostAnalyticsGroupResolver;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use PHPUnit\Framework\TestCase;

final class UnitExtendedQueryTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const PERIOD_FROM = '2026-04-01';
    private const PERIOD_TO = '2026-04-30';

    private MarketplaceFacade $marketplaceFacade;
    private MarketplaceAdsFacade $adsFacade;
    private UnitExtendedQuery $query;

    protected function setUp(): void
    {
        $this->marketplaceFacade = $this->createMock(MarketplaceFacade::class);
        $this->adsFacade = $this->createMock(MarketplaceAdsFacade::class);
        $this->query = new UnitExtendedQuery(
            $this->marketplaceFacade,
            $this->adsFacade,
            new MarketplaceCostAnalyticsGroupResolver(),
        );

        // Defaults — overridden per test where needed
        $this->marketplaceFacade->method('getReturnAggregatesByListing')->willReturn([]);
        $this->marketplaceFacade->method('getCostAggregatesByListing')->willReturn([]);
        $this->marketplaceFacade->method('getListingsMetaByIds')->willReturn([]);
    }

    public function testRowFormulaWithAdSpend(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('l-1', 'Товар', 'SKU-1', 'ozon', '1000.00', 5, '300.00', 5),
        ]);
        $this->stubAdSpend(['l-1' => '150.00']);
        $this->stubTotalAdSpend('150.00');

        $result = $this->execute();

        $row = $this->findRow($result['items'], 'l-1');
        self::assertNotNull($row);
        self::assertSame(150.0, $row['adSpend']);
        // totalCosts = commission(0) + logistics(0) + otherCosts(0) + adSpend(150) = 150
        self::assertSame(150.0, $row['totalCosts']);
        // profit = revenue(1000) - returns(0) - costPrice(300) - 0 - 0 - 0 - adSpend(150) = 550
        self::assertSame(550.0, $row['profit']);
        // drrPercent = 150 / 1000 * 100 = 15.0
        self::assertSame(15.0, $row['drrPercent']);
        // marginPercent = 550 / 1000 * 100 = 55.0
        self::assertSame(55.0, $row['marginPercent']);
        // roiPercent = 550 / 300 * 100 = 183.3
        self::assertSame(183.3, $row['roiPercent']);
    }

    public function testRowDrrPercentIsNullWhenRevenueIsZero(): void
    {
        // Listing with cost row but no sales → revenue = 0; adSpend may exist
        $this->stubSales([]);
        $this->stubCosts([
            'l-2' => [
                new ListingCostCategoryAggregateDTO('l-2', 'ozon_other', 'Other', '50.00', '50.00', '0.00'),
            ],
        ]);
        $this->stubMeta([
            new ListingMetaDTO('l-2', 'Без выручки', 'SKU-2', 'ozon'),
        ]);
        $this->stubAdSpend(['l-2' => '40.00']);
        $this->stubTotalAdSpend('40.00');

        $result = $this->execute();

        $row = $this->findRow($result['items'], 'l-2');
        self::assertNotNull($row);
        self::assertSame(0.0, $row['revenue']);
        self::assertSame(40.0, $row['adSpend']);
        self::assertNull($row['drrPercent'], 'revenue = 0 → drrPercent must be null');
    }

    public function testRowWithoutAdSpendHasZeroAdSpendAndZeroDrr(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('l-3', 'Без РР', 'SKU-3', 'ozon', '500.00', 2, '100.00', 2),
        ]);
        $this->stubMeta([
            new ListingMetaDTO('l-3', 'Без РР', 'SKU-3', 'ozon', 'SUP-3'),
        ]);
        // No ad spend for this listing
        $this->stubAdSpend([]);
        $this->stubTotalAdSpend('0');

        $result = $this->execute();

        $row = $this->findRow($result['items'], 'l-3');
        self::assertNotNull($row);
        self::assertArrayHasKey('sellerArticle', $row);
        self::assertSame('SUP-3', $row['sellerArticle']);
        self::assertSame('SKU-3', $row['sku']);
        self::assertSame(0.0, $row['adSpend']);
        // revenue > 0, adSpend = 0 → drrPercent = 0.0 (not null)
        self::assertSame(0.0, $row['drrPercent']);
        self::assertSame(500.0, $row['revenue']);
        self::assertSame(400.0, $row['profit']);
        self::assertSame(0.0, $result['totals']['adSpend']);
        self::assertSame(500.0, $result['totals']['revenue']);
        self::assertSame(400.0, $result['totals']['profit']);
    }

    public function testListingWithAdSpendOnlyDoesNotAppearInRows(): void
    {
        // Sales on l-A, ad spend on l-A AND on l-B (without sales/costs/returns)
        $this->stubSales([
            new ListingSalesAggregateDTO('l-A', 'Товар А', 'SKU-A', 'ozon', '1000.00', 5, '200.00', 5),
        ]);
        $this->stubAdSpend([
            'l-A' => '100.00',
            'l-B' => '777.77', // listing not present in sales/returns/costs
        ]);
        $this->stubTotalAdSpend('877.77');

        $result = $this->execute();

        $ids = array_map(static fn (array $r): string => (string) $r['listingId'], $result['items']);
        self::assertContains('l-A', $ids);
        self::assertNotContains('l-B', $ids, 'Листинг с РР, но без sales/returns/costs не должен попасть в строки');
    }

    public function testTotalsAdSpendComesFromGetTotalAdCostForPeriodNotFromRowsSum(): void
    {
        // Sum of per-row adSpend = 100, but totals.adSpend must equal facade's full value (250)
        // — that's the design decision (parity with /marketplace-ads/efficiency totals).
        $this->stubSales([
            new ListingSalesAggregateDTO('l-1', 'Товар', 'SKU-1', 'ozon', '1000.00', 5, '300.00', 5),
        ]);
        $this->stubAdSpend(['l-1' => '100.00']);
        $this->stubTotalAdSpend('250.00');

        $result = $this->execute();

        self::assertSame(100.0, $result['items'][0]['adSpend']);
        self::assertSame(250.0, $result['totals']['adSpend']);
        // totals.totalCosts = commission(0) + logistics(0) + otherCosts(0) + 250 = 250
        self::assertSame(250.0, $result['totals']['totalCosts']);
        // totals.profit = revenue(1000) - returns(0) - costPrice(300) - 0 - 0 - 0 - 250 = 450
        self::assertSame(450.0, $result['totals']['profit']);
        // totals.drrPercent = 250 / 1000 * 100 = 25.0
        self::assertSame(25.0, $result['totals']['drrPercent']);
        // marginPercent = 450 / 1000 * 100 = 45.0
        self::assertSame(45.0, $result['totals']['marginPercent']);
        // roiPercent = 450 / 300 * 100 = 150.0
        self::assertSame(150.0, $result['totals']['roiPercent']);
    }

    public function testWbAndOzonClassificationAndTotalsBeforeLimit(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('wb-1', 'WB Товар', 'WB-SKU', 'wildberries', '1000.00', 3, '200.00', 3),
            new ListingSalesAggregateDTO('oz-1', 'Ozon Товар', 'OZ-SKU', 'ozon', '400.00', 2, '120.00', 2),
        ]);

        $this->stubCosts([
            'wb-1' => [
                new ListingCostCategoryAggregateDTO('wb-1', 'commission', 'Комиссия WB', '100.00', '100.00', '0.00'),
                new ListingCostCategoryAggregateDTO('wb-1', 'logistics_delivery', 'Логистика доставка', '50.00', '50.00', '0.00'),
                new ListingCostCategoryAggregateDTO('wb-1', 'logistics_return', 'Логистика возврат', '20.00', '20.00', '0.00'),
                new ListingCostCategoryAggregateDTO('wb-1', 'warehouse_logistics', 'Логистика склада', '30.00', '30.00', '0.00'),
                new ListingCostCategoryAggregateDTO('wb-1', 'acquiring', 'Эквайринг', '10.00', '10.00', '0.00'),
                new ListingCostCategoryAggregateDTO('wb-1', 'wb_okazanie_uslug_wb_prodvizhenie', 'Продвижение WB', '15.00', '15.00', '0.00'),
                new ListingCostCategoryAggregateDTO('wb-1', 'penalty', 'Штраф', '5.00', '5.00', '0.00'),
            ],
            'oz-1' => [
                new ListingCostCategoryAggregateDTO('oz-1', 'ozon_sale_commission', 'Комиссия Ozon', '40.00', '40.00', '0.00'),
                new ListingCostCategoryAggregateDTO('oz-1', 'ozon_logistic_direct', 'Логистика Ozon', '12.00', '12.00', '0.00'),
            ],
        ]);

        $this->stubAdSpend([]);
        $this->stubTotalAdSpend('0');

        $result = $this->query->execute(self::COMPANY_ID, null, self::PERIOD_FROM, self::PERIOD_TO, 1);

        self::assertCount(1, $result['items'], 'limit applies to rows only');
        self::assertSame(140.0, $result['totals']['commission']);
        self::assertSame(112.0, $result['totals']['logistics']);
        self::assertSame(30.0, $result['totals']['otherCosts']);

        $fullResult = $this->query->execute(self::COMPANY_ID, null, self::PERIOD_FROM, self::PERIOD_TO);
        $wb = $this->findRow($fullResult['items'], 'wb-1');
        self::assertNotNull($wb);

        self::assertSame(100.0, $wb['commission']);
        self::assertSame(100.0, $wb['logistics']);
        self::assertSame(30.0, $wb['otherCosts']);

        $allGroups = array_column($wb['allCostsBreakdown'], 'serviceGroup');
        self::assertContains('Услуги партнёров', $allGroups);
        self::assertContains('Продвижение и реклама', $allGroups);
        self::assertContains('Другие услуги и штрафы', $allGroups);

        $partners = $this->findBreakdownGroup($wb['allCostsBreakdown'], 'Услуги партнёров');
        self::assertNotNull($partners);
        self::assertSame('acquiring', $partners['categories'][0]['code']);
        self::assertArrayNotHasKey('marketplace', $partners['categories'][0]);

        $promo = $this->findBreakdownGroup($wb['allCostsBreakdown'], 'Продвижение и реклама');
        self::assertNotNull($promo);

        $penalty = $this->findBreakdownGroup($wb['allCostsBreakdown'], 'Другие услуги и штрафы');
        self::assertNotNull($penalty);
    }
    public function testMarketplaceFilterIsPropagatedToBothAdsFacadeCalls(): void
    {
        $this->stubSales([]);
        $this->stubAdSpend([]);

        $marketplaceArg = 'ozon';
        $from = new \DateTimeImmutable(self::PERIOD_FROM);
        $to = new \DateTimeImmutable(self::PERIOD_TO);

        $this->adsFacade
            ->expects(self::once())
            ->method('getAdSpendByListingForPeriod')
            ->with(self::COMPANY_ID, $from, $to, $marketplaceArg)
            ->willReturn([]);

        $this->adsFacade
            ->expects(self::once())
            ->method('getTotalAdCostForPeriod')
            ->with(self::COMPANY_ID, $from, $to, $marketplaceArg)
            ->willReturn('0');

        $this->query->execute(self::COMPANY_ID, $marketplaceArg, self::PERIOD_FROM, self::PERIOD_TO);
    }

    public function testEmptyEverythingProducesZeroTotals(): void
    {
        $this->stubSales([]);
        $this->stubAdSpend([]);
        $this->stubTotalAdSpend('0');

        $result = $this->execute();

        self::assertSame([], $result['items']);
        self::assertSame(0.0, $result['totals']['revenue']);
        self::assertSame(0.0, $result['totals']['adSpend']);
        self::assertSame(0.0, $result['totals']['totalCosts']);
        self::assertSame(0.0, $result['totals']['profit']);
        self::assertNull($result['totals']['drrPercent']);
        self::assertNull($result['totals']['marginPercent']);
        self::assertNull($result['totals']['roiPercent']);
    }

    /**
     * @param list<ListingSalesAggregateDTO> $sales
     */
    private function stubSales(array $sales): void
    {
        $keyed = [];
        foreach ($sales as $s) {
            $keyed[$s->listingId] = $s;
        }
        $this->marketplaceFacade->method('getSalesAggregatesByListing')->willReturn($keyed);
    }

    /**
     * @param array<string, list<ListingCostCategoryAggregateDTO>> $costs
     */
    private function stubCosts(array $costs): void
    {
        $this->marketplaceFacade->method('getCostAggregatesByListing')->willReturn($costs);
    }

    /**
     * @param list<ListingMetaDTO> $meta
     */
    private function stubMeta(array $meta): void
    {
        $keyed = [];
        foreach ($meta as $m) {
            $keyed[$m->id] = $m;
        }
        $this->marketplaceFacade->method('getListingsMetaByIds')->willReturn($keyed);
    }

    /**
     * @param array<string, string> $byListing
     */
    private function stubAdSpend(array $byListing): void
    {
        $this->adsFacade->method('getAdSpendByListingForPeriod')->willReturn($byListing);
    }

    private function stubTotalAdSpend(string $value): void
    {
        $this->adsFacade->method('getTotalAdCostForPeriod')->willReturn($value);
    }



    /**
     * @param list<array<string, mixed>> $groups
     * @return array<string, mixed>|null
     */
    private function findBreakdownGroup(array $groups, string $serviceGroup): ?array
    {
        foreach ($groups as $group) {
            if (($group['serviceGroup'] ?? null) === $serviceGroup) {
                return $group;
            }
        }

        return null;
    }
    /**
     * @return array{items: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function execute(): array
    {
        return $this->query->execute(
            self::COMPANY_ID,
            'ozon',
            self::PERIOD_FROM,
            self::PERIOD_TO,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function findRow(array $items, string $listingId): ?array
    {
        foreach ($items as $item) {
            if ($item['listingId'] === $listingId) {
                return $item;
            }
        }

        return null;
    }
}
