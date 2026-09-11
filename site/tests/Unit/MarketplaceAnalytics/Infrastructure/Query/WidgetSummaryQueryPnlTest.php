<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\DTO\ListingReturnAggregateDTO;
use App\Marketplace\DTO\ListingSalesAggregateDTO;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Application\Service\MarketplaceCostAnalyticsGroupResolver;
use App\MarketplaceAnalytics\Infrastructure\Query\WidgetSummaryQuery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class WidgetSummaryQueryPnlTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private MarketplaceFacade $facade;
    private Connection $connection;
    private MarketplaceCostAnalyticsGroupResolver $groupResolver;
    private WidgetSummaryQuery $query;

    protected function setUp(): void
    {
        $this->facade = $this->createMock(MarketplaceFacade::class);
        $this->connection = $this->createMock(Connection::class);
        $this->groupResolver = new MarketplaceCostAnalyticsGroupResolver();
        $this->query = new WidgetSummaryQuery($this->facade, $this->connection, $this->groupResolver);
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
            ['marketplace' => 'ozon', 'category_code' => 'ozon_sale_commission', 'category_name' => 'Комиссия', 'costs_amount' => '-500.00', 'storno_amount' => '0.00', 'net_amount' => '-500.00'],
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
            ['marketplace' => 'ozon', 'category_code' => 'ozon_sale_commission', 'category_name' => 'Комиссия', 'costs_amount' => '-1000.00', 'storno_amount' => '300.00', 'net_amount' => '-700.00'],
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
            ['marketplace' => 'ozon', 'category_code' => 'ozon_sale_commission', 'category_name' => 'Комиссия', 'costs_amount' => '-800.00', 'storno_amount' => '0.00', 'net_amount' => '-800.00'],
            ['marketplace' => 'ozon', 'category_code' => 'ozon_logistic_direct', 'category_name' => 'FBO', 'costs_amount' => '-500.00', 'storno_amount' => '100.00', 'net_amount' => '-400.00'],
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
            ['marketplace' => 'ozon', 'category_code' => 'ozon_sale_commission', 'category_name' => 'K', 'costs_amount' => '-3000.00', 'storno_amount' => '0.00', 'net_amount' => '-3000.00'],
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
            ['marketplace' => 'ozon', 'category_code' => 'ozon_sale_commission', 'category_name' => 'Комиссия', 'costs_amount' => '-300.00', 'storno_amount' => '0.00', 'net_amount' => '-300.00'],
            ['marketplace' => 'ozon', 'category_code' => 'ozon_logistic_direct', 'category_name' => 'FBO', 'costs_amount' => '-700.00', 'storno_amount' => '0.00', 'net_amount' => '-700.00'],
            ['marketplace' => 'ozon', 'category_code' => 'ozon_cpc', 'category_name' => 'Реклама', 'costs_amount' => '-100.00', 'storno_amount' => '0.00', 'net_amount' => '-100.00'],
        ]);

        $result = $this->executeSummary();

        $groupNames = array_map(
            static fn (array $g): string => $g['serviceGroup'],
            array_filter($result['widgetGroups'], static fn (array $g): bool => 0.0 !== $g['netAmount']),
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
            ['marketplace' => 'ozon', 'category_code' => 'ozon_compensation', 'category_name' => 'Компенсация', 'costs_amount' => '0.00', 'storno_amount' => '1799.00', 'net_amount' => '1799.00'],
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
     * Компенсация, пришедшая как storno, показывается доходом.
     *
     * Раньше этот тест утверждал другое: что доходом показывается и компенсация
     * с operation_type = 'charge', потому что SQL спец-кейсил код категории. Тот
     * обход убран — направление берётся из operation_type, — и утверждение стало
     * неверным. Проверяется именно storno-строка, какую отдаёт SQL.
     */
    public function testCompensationStornoShownAsIncome(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['marketplace' => 'ozon', 'category_code' => 'ozon_compensation', 'category_name' => 'Компенсации и декомпенсации Ozon', 'costs_amount' => '0.00', 'storno_amount' => '5480.00', 'net_amount' => '5480.00'],
        ]);

        $result = $this->executeSummary();

        $group = $this->findGroup($result['widgetGroups'], 'Другие услуги и штрафы');
        self::assertNotNull($group);
        self::assertSame(5480.0, $group['netAmount']);

        $cat = $group['categories'][0] ?? null;
        self::assertNotNull($cat);
        self::assertSame('ozon_compensation', $cat['code']);
        self::assertSame(5480.0, $cat['netAmount']);
    }

    /**
     * Декомпенсация приходит как charge и показывается расходом (−ABS).
     */
    public function testDecompensationShownAsExpense(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['marketplace' => 'ozon', 'category_code' => 'ozon_decompensation', 'category_name' => 'Декомпенсация Ozon', 'costs_amount' => '-1274.00', 'storno_amount' => '0.00', 'net_amount' => '-1274.00'],
        ]);

        $result = $this->executeSummary();

        $group = $this->findGroup($result['widgetGroups'], 'Другие услуги и штрафы');
        self::assertNotNull($group);
        self::assertSame(-1274.0, $group['netAmount']);

        $cat = $group['categories'][0] ?? null;
        self::assertNotNull($cat);
        self::assertSame('ozon_decompensation', $cat['code']);
        self::assertSame(-1274.0, $cat['netAmount']);
    }

    /**
     * Подгруппа компенсации+декомпенсации (ИП Сухоносов, февраль 2026):
     *   compensation  +5480  +  decompensation  −1274  =  +4206
     * После фикса — корректная сумма по группе «Другие услуги и штрафы».
     */
    public function testCompensationPlusDecompensationNetsToFourThousand(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['marketplace' => 'ozon', 'category_code' => 'ozon_compensation', 'category_name' => 'Компенсации и декомпенсации Ozon', 'costs_amount' => '0.00', 'storno_amount' => '5480.00', 'net_amount' => '5480.00'],
            ['marketplace' => 'ozon', 'category_code' => 'ozon_decompensation', 'category_name' => 'Декомпенсация Ozon', 'costs_amount' => '-1274.00', 'storno_amount' => '0.00', 'net_amount' => '-1274.00'],
        ]);

        $result = $this->executeSummary();

        $group = $this->findGroup($result['widgetGroups'], 'Другие услуги и штрафы');
        self::assertNotNull($group);
        self::assertSame(4206.0, $group['netAmount']);
    }

    /**
     * SQL-контракт: getCostAggregates обязан спец-кейсить category_code
     * для ozon_compensation / ozon_decompensation. Это страховка от случайного
     * отката до pre-fix формулы на чисто-operation_type CASE.
     */
    /**
     * Направление берётся из operation_type у всех строк без исключений.
     *
     * Раньше здесь стоял обход по коду категории — заплатка под исторические
     * данные, где бэкфилл сохранил положительные компенсации как charge. Дыры
     * больше нет: на 11.09.2026 ни одной строки, где обход менял бы исход. А
     * новым данным он навредил бы: разбор by-day пишет знак по каждой записи, и
     * настоящее списание показалось бы доходом.
     */
    public function testSqlTakesDirectionFromOperationTypeOnly(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);

        $capturedSql = null;
        $this->stubCostQuery(static function (string $sql) use (&$capturedSql): array {
            $capturedSql = $sql;

            return [];
        });

        $this->executeSummary();

        self::assertNotNull($capturedSql);
        self::assertStringNotContainsString("cc.code = 'ozon_compensation'", $capturedSql);
        self::assertStringNotContainsString("cc.code = 'ozon_decompensation'", $capturedSql);
        self::assertStringContainsString('c.operation_type AS effective_op', $capturedSql);
        self::assertStringContainsString('c.marketplace AS marketplace', $capturedSql);
        self::assertStringContainsString('GROUP BY marketplace, category_code, category_name', $capturedSql);
    }

    public function testWbCategoriesAreGroupedByWbMapping(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['marketplace' => 'wildberries', 'category_code' => 'commission', 'category_name' => 'Комиссия', 'costs_amount' => '-100.00', 'storno_amount' => '0.00', 'net_amount' => '-100.00'],
            ['marketplace' => 'wildberries', 'category_code' => 'logistics_delivery', 'category_name' => 'Логистика', 'costs_amount' => '-200.00', 'storno_amount' => '0.00', 'net_amount' => '-200.00'],
            ['marketplace' => 'wildberries', 'category_code' => 'acquiring', 'category_name' => 'Эквайринг', 'costs_amount' => '-300.00', 'storno_amount' => '0.00', 'net_amount' => '-300.00'],
            ['marketplace' => 'wildberries', 'category_code' => 'wb_okazanie_uslug_wb_prodvizhenie', 'category_name' => 'Продвижение', 'costs_amount' => '-400.00', 'storno_amount' => '0.00', 'net_amount' => '-400.00'],
            ['marketplace' => 'wildberries', 'category_code' => 'penalty', 'category_name' => 'Штраф', 'costs_amount' => '-500.00', 'storno_amount' => '0.00', 'net_amount' => '-500.00'],
        ]);

        $result = $this->executeSummaryFor('wildberries');

        self::assertSame('commission', $this->findCategory($result['widgetGroups'], 'Вознаграждение')['code']);
        self::assertSame('logistics_delivery', $this->findCategory($result['widgetGroups'], 'Услуги доставки и FBO')['code']);
        self::assertSame('acquiring', $this->findCategory($result['widgetGroups'], 'Услуги партнёров')['code']);
        self::assertSame('wb_okazanie_uslug_wb_prodvizhenie', $this->findCategory($result['widgetGroups'], 'Продвижение и реклама')['code']);
        self::assertSame('penalty', $this->findCategory($result['widgetGroups'], 'Другие услуги и штрафы')['code']);
    }

    public function testUnknownWbCodeFallsBackToOtherServicesAndPenalties(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['marketplace' => 'wildberries', 'category_code' => 'wb_unknown_code', 'category_name' => 'Неизвестно', 'costs_amount' => '-50.00', 'storno_amount' => '0.00', 'net_amount' => '-50.00'],
        ]);

        $result = $this->executeSummaryFor('wildberries');
        self::assertSame('wb_unknown_code', $this->findCategory($result['widgetGroups'], 'Другие услуги и штрафы')['code']);
    }

    public function testMarketplaceNullDoesNotMergeCategoriesWithSameCode(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);
        $this->stubCostRows([
            ['marketplace' => 'ozon', 'category_code' => 'same_unknown_code', 'category_name' => 'Ozon unknown', 'costs_amount' => '-70.00', 'storno_amount' => '0.00', 'net_amount' => '-70.00'],
            ['marketplace' => 'wildberries', 'category_code' => 'same_unknown_code', 'category_name' => 'WB unknown', 'costs_amount' => '-30.00', 'storno_amount' => '0.00', 'net_amount' => '-30.00'],
        ]);

        $result = $this->executeSummaryFor(null);

        $group = $this->findGroup($result['widgetGroups'], 'Другие услуги и штрафы');
        self::assertNotNull($group);
        self::assertCount(2, $group['categories']);
    }

    /**
     * Скоуп листингов сужает продажи и возвраты. До фикса виджеты его не знали
     * и под фильтром по тегам показывали выручку по всей компании, расходясь
     * со строкой «Итого» таблицы на той же странице.
     */
    public function testListingScopeRestrictsSalesAndReturns(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('l1', 'В скоупе', 'SKU1', 'ozon', '1000.00', 5, '400.00', 5),
            new ListingSalesAggregateDTO('l2', 'Вне скоупа', 'SKU2', 'ozon', '9000.00', 9, '3000.00', 9),
        ]);
        $this->stubReturns([
            new ListingReturnAggregateDTO('l1', '100.00', 1),
            new ListingReturnAggregateDTO('l2', '700.00', 2),
        ]);
        $this->stubCostRows([]);

        $result = $this->executeSummaryScopedTo(['l1']);

        self::assertSame(1000.0, $result['revenue'], 'Выручка должна считаться только по листингам из скоупа');
        self::assertSame(-100.0, $result['returnsTotal']);
        self::assertSame(-400.0, $result['costPriceTotal']);
    }

    /**
     * Затраты в скоупе берутся только по его листингам. Строки с listing_id IS NULL
     * (CPC, хранение) к конкретному тегу не относятся и выпадают — то же решение,
     * что принято для totals.adSpend в UnitExtendedQuery.
     */
    public function testListingScopeRestrictsCostsBySqlFilter(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);

        [$sql, $params, $types] = $this->captureCostQuery(['l1', 'l2']);

        self::assertNotNull($sql);
        self::assertStringContainsString('c.listing_id IN (:listingIds)', $sql);
        self::assertSame(['l1', 'l2'], $params['listingIds'] ?? null);
        self::assertSame(ArrayParameterType::STRING, $types['listingIds'] ?? null);
    }

    /**
     * Без скоупа поведение прежнее: затраты не ограничены листингами, иначе из
     * виджетов пропали бы категории с listing_id IS NULL.
     */
    public function testWithoutListingScopeCostsAreNotRestricted(): void
    {
        $this->stubSales([]);
        $this->stubReturns([]);

        [$sql, $params] = $this->captureCostQuery(null);

        self::assertNotNull($sql);
        self::assertStringNotContainsString('listing_id', $sql);
        self::assertArrayNotHasKey('listingIds', $params);
    }

    /**
     * Пустой скоуп (тег не выбрал ни одного листинга) — нули, а не полная выручка.
     * И без похода в БД: `IN ()` Postgres не примет.
     */
    public function testEmptyListingScopeYieldsZerosWithoutQuery(): void
    {
        $this->stubSales([
            new ListingSalesAggregateDTO('l1', 'Товар', 'SKU1', 'ozon', '1000.00', 5, '400.00', 5),
        ]);
        $this->stubReturns([]);

        [$sql] = $this->captureCostQuery([]);
        self::assertNull($sql, 'При пустом скоупе SQL выполняться не должен');

        $result = $this->executeSummaryScopedTo([]);

        self::assertSame(0.0, $result['revenue']);
        self::assertSame(0.0, $result['totalCosts']);
        self::assertSame(0.0, $result['profit']);
        self::assertNull($result['marginPercent']);
    }

    /**
     * @param list<string>|null $listingIds
     *
     * @return array{0: string|null, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function captureCostQuery(?array $listingIds): array
    {
        $capturedSql = null;
        $capturedParams = [];
        $capturedTypes = [];

        $this->stubCostQuery(
            static function (string $sql, array $params = [], array $types = []) use (
                &$capturedSql,
                &$capturedParams,
                &$capturedTypes,
            ): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                $capturedTypes = $types;

                return [];
            }
        );

        $this->executeSummaryScopedTo($listingIds);

        return [$capturedSql, $capturedParams, $capturedTypes];
    }

    /**
     * @param list<string>|null $listingIds
     *
     * @return array<string, mixed>
     */
    private function executeSummaryScopedTo(?array $listingIds): array
    {
        return $this->query->getSummary(
            self::COMPANY_ID,
            'ozon',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            $listingIds,
        );
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
     * @param list<array{marketplace?: string, category_code: string, category_name: string, costs_amount: string, storno_amount: string, net_amount: string}> $rows
     */
    private function stubCostRows(array $rows): void
    {
        foreach ($rows as &$row) {
            $row['marketplace'] = $row['marketplace'] ?? 'ozon';
        }
        unset($row);
        $this->stubCostQuery(static fn (): array => $rows);
    }

    /**
     * Единственное место, где стабится Connection: PHPStan не выводит тип мока
     * и на каждый такой вызов заводит запись в baseline.
     *
     * @param callable(string, array<string, mixed>, array<string, mixed>): list<array<string, mixed>> $handler
     */
    private function stubCostQuery(callable $handler): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnCallback($handler);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeSummary(): array
    {
        return $this->executeSummaryFor('ozon');
    }

    /**
     * @return array<string, mixed>
     */
    private function executeSummaryFor(?string $marketplace): array
    {
        return $this->query->getSummary(
            self::COMPANY_ID,
            $marketplace,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );
    }

    /**
     * @param list<array<string, mixed>> $widgetGroups
     *
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

    /**
     * @param list<array<string, mixed>> $widgetGroups
     *
     * @return array<string, mixed>
     */
    private function findCategory(array $widgetGroups, string $serviceGroup): array
    {
        $group = $this->findGroup($widgetGroups, $serviceGroup);
        self::assertNotNull($group);
        $category = $group['categories'][0] ?? null;
        self::assertNotNull($category);

        /* @var array<string, mixed> $category */
        return $category;
    }
}
