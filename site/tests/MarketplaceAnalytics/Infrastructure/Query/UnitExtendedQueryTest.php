<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\DTO\ListingCostCategoryAggregateDTO;
use App\Marketplace\DTO\ListingMetaDTO;
use App\Marketplace\DTO\ListingReturnAggregateDTO;
use App\Marketplace\DTO\ListingSalesAggregateDTO;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
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
        $this->query = new UnitExtendedQuery($this->marketplaceFacade, $this->adsFacade);

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
                new ListingCostCategoryAggregateDTO('l-2', 'ozon_other', 'Other', '-50.00', '-50.00', '0.00'),
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
        // No ad spend for this listing
        $this->stubAdSpend([]);
        $this->stubTotalAdSpend('0');

        $result = $this->execute();

        $row = $this->findRow($result['items'], 'l-3');
        self::assertNotNull($row);
        self::assertSame(0.0, $row['adSpend']);
        // revenue > 0, adSpend = 0 → drrPercent = 0.0 (not null)
        self::assertSame(0.0, $row['drrPercent']);
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
