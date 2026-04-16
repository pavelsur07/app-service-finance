<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\DTO\ListingReturnAggregateDTO;
use App\Marketplace\DTO\ListingSalesAggregateDTO;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Infrastructure\Query\WidgetSummaryQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class WidgetSummaryQueryPnlTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private MarketplaceFacade $facade;
    private Connection $connection;
    private WidgetSummaryQuery $query;

    protected function setUp(): void
    {
        $this->facade = $this->createMock(MarketplaceFacade::class);
        $this->connection = $this->createMock(Connection::class);
        $this->query = new WidgetSummaryQuery($this->facade, $this->connection);
    }

    public function testReturnsNegativeReturnsTotal(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('l1', 'Product', 'SKU1', 'ozon', '1000.00', 5, '0.00', 0),
        ]);
        $this->stubReturns([
            new ListingReturnAggregateDTO('l1', '200.00', 2),
        ]);
        $this->stubCostRows([]);

        $result = $this->executeSummary();

        self::assertSame(1000.0, $result['revenue']);
        self::assertSame(-200.0, $result['returnsTotal']);
    }

    public function testReturnsNegativeCostPriceTotal(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('l1', 'Product', 'SKU1', 'ozon', '5000.00', 10, '800.00', 10),
        ]);
        $this->stubReturns([]);
        $this->stubCostRows([]);

        $result = $this->executeSummary();

        self::assertSame(5000.0, $result['revenue']);
        self::assertSame(-800.0, $result['costPriceTotal']);
    }

    public function testCostGroupNetAmountIsNegative(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['category_code' => 'ozon_sale_commission', 'category_name' => 'Комиссия', 'costs_amount' => '-500.00', 'storno_amount' => '0.00', 'net_amount' => '-500.00'],
        ]);

        $result = $this->executeSummary();

        $group = $this->findGroup($result['widgetGroups'], 'Вознаграждение');
        self::assertNotNull($group);
        self::assertSame(-500.0, $group['netAmount']);
        self::assertSame(-500.0, $group['costsAmount']);
        self::assertSame(0.0, $group['stornoAmount']);
    }

    public function testStornoIsPositiveInPnl(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['category_code' => 'ozon_sale_commission', 'category_name' => 'Комиссия', 'costs_amount' => '-1000.00', 'storno_amount' => '300.00', 'net_amount' => '-700.00'],
        ]);

        $result = $this->executeSummary();

        $group = $this->findGroup($result['widgetGroups'], 'Вознаграждение');
        self::assertNotNull($group);
        self::assertSame(-700.0, $group['netAmount']);
        self::assertSame(300.0, $group['stornoAmount']);
    }

    public function testProfitFormulaAddition(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('l1', 'Товар', 'SKU1', 'ozon', '5000.00', 10, '1000.00', 10),
        ]);
        $this->stubReturns([
            new ListingReturnAggregateDTO('l1', '200.00', 1),
        ]);
        $this->stubCostRows([
            ['category_code' => 'ozon_sale_commission', 'category_name' => 'Комиссия', 'costs_amount' => '-800.00', 'storno_amount' => '0.00', 'net_amount' => '-800.00'],
            ['category_code' => 'ozon_logistic_direct', 'category_name' => 'FBO', 'costs_amount' => '-500.00', 'storno_amount' => '100.00', 'net_amount' => '-400.00'],
        ]);

        $result = $this->executeSummary();

        // revenue=5000, returnsTotal=-200, costPriceTotal=-1000, totalCosts=-1200
        // profit = 5000 + (-200) + (-1000) + (-1200) = 2600
        self::assertSame(5000.0, $result['revenue']);
        self::assertSame(-200.0, $result['returnsTotal']);
        self::assertSame(-1000.0, $result['costPriceTotal']);
        self::assertSame(-1200.0, $result['totalCosts']);
        self::assertSame(2600.0, $result['profit']);
    }

    public function testMarginPercentCalculation(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('l1', 'T', 'S', 'ozon', '10000.00', 10, '2000.00', 10),
        ]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['category_code' => 'ozon_sale_commission', 'category_name' => 'K', 'costs_amount' => '-3000.00', 'storno_amount' => '0.00', 'net_amount' => '-3000.00'],
        ]);

        $result = $this->executeSummary();

        // profit = 10000 + 0 + (-2000) + (-3000) = 5000
        // margin = 5000 / 10000 * 100 = 50.0
        self::assertSame(5000.0, $result['profit']);
        self::assertSame(50.0, $result['marginPercent']);
    }

    public function testEmptyDataReturnsZeros(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([]);

        $result = $this->executeSummary();

        self::assertSame(0.0, $result['revenue']);
        self::assertSame(0.0, $result['returnsTotal']);
        self::assertSame(0.0, $result['costPriceTotal']);
        self::assertSame(0.0, $result['totalCosts']);
        self::assertSame(0.0, $result['profit']);
        self::assertNull($result['marginPercent']);
    }

    public function testWidgetGroupsSortedByNetAmountAscending(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['category_code' => 'ozon_sale_commission', 'category_name' => 'Комиссия', 'costs_amount' => '-300.00', 'storno_amount' => '0.00', 'net_amount' => '-300.00'],
            ['category_code' => 'ozon_logistic_direct', 'category_name' => 'FBO', 'costs_amount' => '-700.00', 'storno_amount' => '0.00', 'net_amount' => '-700.00'],
            ['category_code' => 'ozon_cpc', 'category_name' => 'Реклама', 'costs_amount' => '-100.00', 'storno_amount' => '0.00', 'net_amount' => '-100.00'],
        ]);

        $result = $this->executeSummary();

        $groupNames = array_map(
            static fn (array $g): string => $g['serviceGroup'],
            array_filter($result['widgetGroups'], static fn (array $g): bool => $g['netAmount'] !== 0.0),
        );
        $groupNames = array_values($groupNames);

        self::assertSame('Услуги доставки и FBO', $groupNames[0]);
        self::assertSame('Вознаграждение', $groupNames[1]);
        self::assertSame('Продвижение и реклама', $groupNames[2]);
    }

    public function testCompensationStornoBecomesPositiveNet(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['category_code' => 'ozon_compensation', 'category_name' => 'Компенсация', 'costs_amount' => '0.00', 'storno_amount' => '1799.00', 'net_amount' => '1799.00'],
        ]);

        $result = $this->executeSummary();

        $group = $this->findGroup($result['widgetGroups'], 'Другие услуги и штрафы');
        self::assertNotNull($group);
        self::assertSame(1799.0, $group['netAmount']);

        $cat = $group['categories'][0] ?? null;
        self::assertNotNull($cat);
        self::assertSame('ozon_compensation', $cat['code']);
        self::assertSame(1799.0, $cat['netAmount']);
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
        $this->facade->method('getSalesAggregatesByListing')->willReturn($keyed);
    }

    /**
     * @param list<ListingReturnAggregateDTO> $returns
     */
    private function stubReturns(array $returns): void
    {
        $keyed = [];
        foreach ($returns as $r) {
            $keyed[$r->listingId] = $r;
        }
        $this->facade->method('getReturnAggregatesByListing')->willReturn($keyed);
    }

    /**
     * @param list<array{category_code: string, category_name: string, costs_amount: string, storno_amount: string, net_amount: string}> $rows
     */
    private function stubCostRows(array $rows): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeSummary(): array
    {
        return $this->query->getSummary(
            self::COMPANY_ID,
            'ozon',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );
    }

    /**
     * @param list<array<string, mixed>> $widgetGroups
     * @return array<string, mixed>|null
     */
    private function findGroup(array $widgetGroups, string $serviceGroup): ?array
    {
        foreach ($widgetGroups as $g) {
            if ($g['serviceGroup'] === $serviceGroup) {
                return $g;
            }
        }

        return null;
    }
}
