<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\Internal\ActiveConnectionCredentials;
use App\Marketplace\Application\Service\MarketplaceConnectionsSnapshotBuilder;
use App\Marketplace\Enum\MarketplaceConnectionType;
use PHPUnit\Framework\TestCase;

final class MarketplaceConnectionsSnapshotBuilderTest extends TestCase
{
    private const COMPANY_A = '11111111-1111-7111-8111-111111111111';
    private const COMPANY_B = '22222222-2222-7222-8222-222222222222';
    private const SELLER_A = 'aaaaaaaa-0000-7000-8000-00000000000a';
    private const PERF_A = 'aaaaaaaa-0000-7000-8000-0000000000fa';
    private const PERF_B = 'bbbbbbbb-0000-7000-8000-0000000000fb';

    public function testSellerAndPerformanceOfOneCompanyBecomeOneCabinetReferencedBySeller(): void
    {
        $snapshot = $this->build([
            $this->seller(self::SELLER_A, self::COMPANY_A, updatedAt: '2026-10-01 10:00:00'),
            $this->performance(self::PERF_A, self::COMPANY_A, updatedAt: '2026-10-05 10:00:00'),
        ]);

        self::assertCount(1, $snapshot['connections']);
        $cabinet = $snapshot['connections'][0];
        self::assertSame(self::SELLER_A, $cabinet['connection_ref']);
        self::assertSame(self::COMPANY_A, $cabinet['company_id']);
        self::assertSame('ozon', $cabinet['provider']);
        self::assertSame(['client_id' => 'seller-client', 'api_key' => 'seller-key'], $cabinet['credentials']['seller']);
        self::assertSame(['client_id' => 'perf-client', 'client_secret' => 'perf-secret'], $cabinet['credentials']['performance']);
        self::assertSame([...MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES], $cabinet['resource_types']);
        self::assertSame((new \DateTimeImmutable('2026-10-05 10:00:00'))->format(\DateTimeInterface::ATOM), $cabinet['updated_at']);
    }

    public function testPerformanceOnlyCompanyIsReferencedByPerformanceConnection(): void
    {
        $cabinet = $this->build([$this->performance(self::PERF_B, self::COMPANY_B)])['connections'][0];

        self::assertSame(self::PERF_B, $cabinet['connection_ref']);
        self::assertNull($cabinet['credentials']['seller']);
        self::assertSame([], $cabinet['resource_types']);
    }

    public function testUnusableSellerKeepsItsRefButLosesFinanceResources(): void
    {
        $cabinet = $this->build([
            $this->seller(self::SELLER_A, self::COMPANY_A, clientId: null),
            $this->performance(self::PERF_A, self::COMPANY_A),
        ])['connections'][0];

        self::assertSame(self::SELLER_A, $cabinet['connection_ref']);
        self::assertNull($cabinet['credentials']['seller']);
        self::assertSame([], $cabinet['resource_types']);
    }

    public function testCabinetWithoutUsableCredentialsIsNotServed(): void
    {
        $snapshot = $this->build([
            $this->seller(self::SELLER_A, self::COMPANY_A, secret: null),
            $this->performance(self::PERF_A, self::COMPANY_A, clientId: '  '),
        ]);

        self::assertSame([], $snapshot['connections']);
    }

    public function testCabinetsAreOrderedByRefAndEnvelopeFollowsContract(): void
    {
        $snapshot = $this->build([
            $this->performance(self::PERF_B, self::COMPANY_B),
            $this->seller(self::SELLER_A, self::COMPANY_A),
        ]);

        self::assertSame('1', $snapshot['schema_version']);
        self::assertSame('2026-10-17T12:00:00+00:00', $snapshot['generated_at']);
        self::assertSame([self::SELLER_A, self::PERF_B], array_column($snapshot['connections'], 'connection_ref'));
    }

    public function testOrdersResourcesOnlyForListedCompaniesWithSellerKey(): void
    {
        $rows = [
            $this->seller(self::SELLER_A, self::COMPANY_A),
            $this->performance(self::PERF_B, self::COMPANY_B),
        ];

        $none = $this->build($rows)['connections'];
        self::assertSame(MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES, $none[0]['resource_types'], 'по умолчанию заказы никому');

        $one = $this->build($rows, [self::COMPANY_A, self::COMPANY_B])['connections'];
        self::assertSame([...MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES, ...MarketplaceConnectionsSnapshotBuilder::ORDERS_RESOURCE_TYPES], $one[0]['resource_types']);
        self::assertSame([], $one[1]['resource_types'], 'без ключа seller заказов нет');
    }

    public function testAccrualPostingsOnlyForListedCompaniesThatHaveOrders(): void
    {
        $rows = [
            $this->seller(self::SELLER_A, self::COMPANY_A),
            $this->seller('cccccccc-0000-7000-8000-00000000000c', self::COMPANY_B),
        ];

        $none = $this->build($rows, [MarketplaceConnectionsSnapshotBuilder::ORDERS_ALL_COMPANIES])['connections'];
        self::assertNotContains(MarketplaceConnectionsSnapshotBuilder::ACCRUAL_POSTINGS_RESOURCE_TYPE, $none[0]['resource_types'], 'по умолчанию — никому');

        $one = $this->build($rows, [MarketplaceConnectionsSnapshotBuilder::ORDERS_ALL_COMPANIES], [self::COMPANY_A])['connections'];
        $byCompany = array_column($one, 'resource_types', 'company_id');
        self::assertContains(MarketplaceConnectionsSnapshotBuilder::ACCRUAL_POSTINGS_RESOURCE_TYPE, $byCompany[self::COMPANY_A]);
        self::assertNotContains(MarketplaceConnectionsSnapshotBuilder::ACCRUAL_POSTINGS_RESOURCE_TYPE, $byCompany[self::COMPANY_B]);

        $withoutOrders = $this->build($rows, [], [MarketplaceConnectionsSnapshotBuilder::ORDERS_ALL_COMPANIES])['connections'];
        foreach ($withoutOrders as $cabinet) {
            self::assertNotContains(MarketplaceConnectionsSnapshotBuilder::ACCRUAL_POSTINGS_RESOURCE_TYPE, $cabinet['resource_types'], 'без заказов номеров нет');
        }
    }

    public function testProductsOnlyForListedCompaniesWithSellerKey(): void
    {
        $rows = [
            $this->seller(self::SELLER_A, self::COMPANY_A),
            $this->performance(self::PERF_B, self::COMPANY_B),
            $this->seller('cccccccc-0000-7000-8000-00000000000c', 'dddddddd-0000-7000-8000-00000000000d'),
        ];

        foreach ($this->build($rows)['connections'] as $cabinet) {
            self::assertNotContains('ozon.products.list', $cabinet['resource_types'], 'по умолчанию — никому');
        }

        $one = array_column($this->build($rows, [], [], [self::COMPANY_A, self::COMPANY_B])['connections'], 'resource_types', 'company_id');
        self::assertSame([...MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES, ...MarketplaceConnectionsSnapshotBuilder::PRODUCTS_RESOURCE_TYPES], $one[self::COMPANY_A], 'не зависит от заказов');
        self::assertSame([], $one[self::COMPANY_B], 'без ключа seller каталога нет');
        self::assertNotContains('ozon.products.info', $one['dddddddd-0000-7000-8000-00000000000d'], 'компания не в списке');

        foreach ($this->build($rows, [], [], [MarketplaceConnectionsSnapshotBuilder::ORDERS_ALL_COMPANIES])['connections'] as $cabinet) {
            if (isset($cabinet['credentials']['seller'])) {
                self::assertContains('ozon.products.list', $cabinet['resource_types']);
                self::assertContains('ozon.products.info', $cabinet['resource_types']);
            }
        }
    }

    public function testStocksOnlyForListedCompaniesWithSellerKey(): void
    {
        $rows = [
            $this->seller(self::SELLER_A, self::COMPANY_A),
            $this->performance(self::PERF_B, self::COMPANY_B),
            $this->seller('cccccccc-0000-7000-8000-00000000000c', 'dddddddd-0000-7000-8000-00000000000d'),
        ];

        foreach ($this->build($rows)['connections'] as $cabinet) {
            self::assertNotContains('ozon.stocks', $cabinet['resource_types'], 'по умолчанию — никому');
        }

        $one = array_column($this->build($rows, [], [], [], [self::COMPANY_A, self::COMPANY_B])['connections'], 'resource_types', 'company_id');
        self::assertSame([...MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES, ...MarketplaceConnectionsSnapshotBuilder::STOCKS_RESOURCE_TYPES], $one[self::COMPANY_A]);
        self::assertSame([], $one[self::COMPANY_B], 'без ключа seller остатков нет');
        self::assertNotContains('ozon.stocks', $one['dddddddd-0000-7000-8000-00000000000d'], 'компания не в списке');
    }

    public function testPricesAndWarehouseStocksOnlyForListedCompaniesWithSellerKey(): void
    {
        $rows = [
            $this->seller(self::SELLER_A, self::COMPANY_A),
            $this->performance(self::PERF_B, self::COMPANY_B),
            $this->seller('cccccccc-0000-7000-8000-00000000000c', 'dddddddd-0000-7000-8000-00000000000d'),
        ];
        $all = [self::COMPANY_A, self::COMPANY_B];

        foreach ($this->build($rows, [], [], [], $all)['connections'] as $cabinet) {
            self::assertNotContains('ozon.prices', $cabinet['resource_types'], 'по умолчанию — никому');
            self::assertNotContains('ozon.stocks.warehouses', $cabinet['resource_types']);
        }

        $both = array_column($this->build($rows, [], [], [], $all, [], [], $all)['connections'], 'resource_types', 'company_id');
        self::assertSame([
            ...MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES,
            ...MarketplaceConnectionsSnapshotBuilder::STOCKS_RESOURCE_TYPES,
            MarketplaceConnectionsSnapshotBuilder::PRICES_RESOURCE_TYPE,
            MarketplaceConnectionsSnapshotBuilder::WAREHOUSE_STOCKS_RESOURCE_TYPE,
        ], $both[self::COMPANY_A]);
        self::assertSame([], $both[self::COMPANY_B], 'без ключа seller снимков нет');
        self::assertNotContains('ozon.prices', $both['dddddddd-0000-7000-8000-00000000000d'], 'компания не в списке');

        $withoutStocks = array_column($this->build($rows, [], [], [], [], [], [], $all)['connections'], 'resource_types', 'company_id');
        self::assertSame([...MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES, MarketplaceConnectionsSnapshotBuilder::PRICES_RESOURCE_TYPE], $withoutStocks[self::COMPANY_A], 'остатки по складам — только со снимком остатков: SKU берутся из него');
    }

    public function testAdsOnlyForListedCompaniesWithPerformanceKey(): void
    {
        $rows = [
            $this->seller(self::SELLER_A, self::COMPANY_A),
            $this->performance(self::PERF_B, self::COMPANY_B),
        ];

        foreach ($this->build($rows)['connections'] as $cabinet) {
            self::assertNotContains('ozon.ads.expense', $cabinet['resource_types'], 'по умолчанию — никому');
        }

        $one = array_column($this->build($rows, [], [], [], [], [self::COMPANY_A, self::COMPANY_B])['connections'], 'resource_types', 'company_id');
        self::assertSame(MarketplaceConnectionsSnapshotBuilder::SELLER_RESOURCE_TYPES, $one[self::COMPANY_A], 'без ключа performance рекламы нет');
        self::assertSame(MarketplaceConnectionsSnapshotBuilder::ADS_RESOURCE_TYPES, $one[self::COMPANY_B]);
    }

    public function testAdsReportsOnlyWithAdsForListedCompanies(): void
    {
        $rows = [$this->performance(self::PERF_B, self::COMPANY_B)];

        $none = $this->build($rows, [], [], [], [], [self::COMPANY_B])['connections'][0]['resource_types'];
        self::assertSame(MarketplaceConnectionsSnapshotBuilder::ADS_RESOURCE_TYPES, $none, 'отчёты — по своему списку');

        $reports = $this->build($rows, [], [], [], [], [self::COMPANY_B], [self::COMPANY_B])['connections'][0]['resource_types'];
        self::assertSame([...MarketplaceConnectionsSnapshotBuilder::ADS_RESOURCE_TYPES, ...MarketplaceConnectionsSnapshotBuilder::ADS_REPORTS_RESOURCE_TYPES], $reports);

        $withoutAds = $this->build($rows, [], [], [], [], [], [self::COMPANY_B])['connections'][0]['resource_types'];
        self::assertSame([], $withoutAds, 'без рекламы этапа 6 отчётов нет: порции — из её кампаний');
    }

    public function testOrdersResourcesForAllCompanies(): void
    {
        $cabinets = $this->build([
            $this->seller(self::SELLER_A, self::COMPANY_A),
            $this->seller('cccccccc-0000-7000-8000-00000000000c', self::COMPANY_B),
        ], [MarketplaceConnectionsSnapshotBuilder::ORDERS_ALL_COMPANIES])['connections'];

        foreach ($cabinets as $cabinet) {
            self::assertContains('ozon.orders.fbo', $cabinet['resource_types']);
            self::assertContains('ozon.orders.fbs', $cabinet['resource_types']);
        }
    }

    /**
     * @param list<ActiveConnectionCredentials> $rows
     * @param list<string> $ordersCompanyIds
     * @param list<string> $accrualPostingsCompanyIds
     * @param list<string> $productsCompanyIds
     * @param list<string> $stocksCompanyIds
     * @param list<string> $adsCompanyIds
     * @param list<string> $adsReportsCompanyIds
     * @param list<string> $snapshotsCompanyIds
     *
     * @return array<string, mixed>
     */
    private function build(array $rows, array $ordersCompanyIds = [], array $accrualPostingsCompanyIds = [], array $productsCompanyIds = [], array $stocksCompanyIds = [], array $adsCompanyIds = [], array $adsReportsCompanyIds = [], array $snapshotsCompanyIds = []): array
    {
        return (new MarketplaceConnectionsSnapshotBuilder($ordersCompanyIds, $accrualPostingsCompanyIds, $productsCompanyIds, $stocksCompanyIds, $adsCompanyIds, $adsReportsCompanyIds, $snapshotsCompanyIds))->build($rows, new \DateTimeImmutable('2026-10-17 12:00:00 UTC'))->toArray();
    }

    private function seller(string $id, string $companyId, ?string $clientId = 'seller-client', ?string $secret = 'seller-key', string $updatedAt = '2026-10-01 10:00:00'): ActiveConnectionCredentials
    {
        return new ActiveConnectionCredentials($id, $companyId, MarketplaceConnectionType::SELLER, $clientId, $secret, new \DateTimeImmutable($updatedAt));
    }

    private function performance(string $id, string $companyId, ?string $clientId = 'perf-client', ?string $secret = 'perf-secret', string $updatedAt = '2026-10-01 10:00:00'): ActiveConnectionCredentials
    {
        return new ActiveConnectionCredentials($id, $companyId, MarketplaceConnectionType::PERFORMANCE, $clientId, $secret, new \DateTimeImmutable($updatedAt));
    }
}
