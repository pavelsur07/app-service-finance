<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Service\Integration;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WildberriesAdapterTest extends TestCase
{
    public function testFetchRawReportDelegatesViaFinanceEndpoint(): void
    {
        $calls = 0;
        $capturedUrl = '';

        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$calls): MockResponse {
            $capturedUrl = $url;
            $calls++;

            return $calls === 1
                ? new MockResponse('[{"rrdId":10}]', ['http_code' => 200])
                : new MockResponse('', ['http_code' => 204]);
        });

        $adapter = $this->createAdapter($http);
        $rows = $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));

        self::assertSame([['rrdId' => 10]], $rows);
        self::assertSame('https://finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed', $capturedUrl);
        self::assertStringNotContainsString('/api/v5/supplier/reportDetailByPeriod', $capturedUrl);
        self::assertSame(2, $calls);
    }

    public function testHasRawReportDataUsesFinanceEndpointNotLegacyV5(): void
    {
        $capturedUrl = '';
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            return new MockResponse('[{"rrdId":10}]', ['http_code' => 200]);
        });
        $http = new MockHttpClient(static function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;
            return new MockResponse('[{"rrdId":10}]', ['http_code' => 200]);
        });

        $adapter = $this->createAdapter($http);
        self::assertTrue($adapter->hasRawReportData($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
        self::assertStringNotContainsString('/api/v5/supplier/reportDetailByPeriod', $capturedUrl);
    }

    public function testAuthenticateUsesProbeAccess(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 204]));
        $adapter = $this->createAdapter($http);
        self::assertTrue($adapter->authenticate($this->company()));
    }

    public function testGetApiEndpointNameReturnsFinanceEndpoint(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient(new MockResponse('', ['http_code' => 204])));
        self::assertSame('wildberries::finance-sales-reports-detailed', $adapter->getApiEndpointName());
    }

    public function testLegacyFetchSalesUsesRetailPriceWithDiscInsteadOfRetailAmount(): void
    {
        $payload = json_encode([['doc_type_name' => 'Продажа', 'rrdId' => 123, 'rrd_id' => '123', 'sale_dt' => '2026-01-10 10:00:00', 'sa_name' => 'SKU-1', 'quantity' => 2, 'retail_amount' => 9999, 'retail_price_withdisc_rub' => 1125]], JSON_THROW_ON_ERROR);
        $adapter = $this->createAdapter(new MockHttpClient([new MockResponse($payload, ['http_code' => 200]), new MockResponse('', ['http_code' => 204])]));
        $sales = $adapter->fetchSales($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        self::assertCount(1, $sales);
        self::assertSame('1125', $sales[0]->totalRevenue);
        self::assertSame('562.5', $sales[0]->pricePerUnit);
    }

    public function testLegacyFetchReturnsUsesRetailPriceWithDiscInsteadOfRetailAmount(): void
    {
        $payload = json_encode([['doc_type_name' => 'Возврат', 'rrdId' => 124, 'rrd_id' => '124', 'rr_dt' => '2026-01-10 10:00:00', 'sa_name' => 'SKU-1', 'quantity' => 1, 'retail_amount' => 9999, 'retail_price_withdisc_rub' => 1125]], JSON_THROW_ON_ERROR);
        $adapter = $this->createAdapter(new MockHttpClient([new MockResponse($payload, ['http_code' => 200]), new MockResponse('', ['http_code' => 204])]));
        $returns = $adapter->fetchReturns($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        self::assertCount(1, $returns);
        self::assertSame('1125', $returns[0]->refundAmount);
    }

    public function testLegacyFetchCostsDoesNotCreateCommissionForReturnBecauseCostDataHasNoStorno(): void
    {
        $payload = json_encode([['doc_type_name' => 'Возврат', 'supplier_oper_name' => 'Возврат покупателем', 'rrdId' => 125, 'rrd_id' => '125', 'rr_dt' => '2026-01-10 10:00:00', 'sale_dt' => '2026-01-10 10:00:00', 'sa_name' => 'SKU-1', 'quantity' => 1, 'retail_price_withdisc_rub' => 1125.00, 'ppvz_for_pay' => 680.99, 'acquiring_fee' => 27.76]], JSON_THROW_ON_ERROR);
        $adapter = $this->createAdapter(new MockHttpClient([new MockResponse($payload, ['http_code' => 200]), new MockResponse('', ['http_code' => 204])]));
        self::assertSame([], $adapter->fetchCosts($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
    }

    public function testLegacyFetchCostsForSaleUsesCommissionFormulaAndExternalIdByRrdId(): void
    {
        $payload = json_encode([['doc_type_name' => 'Продажа', 'rrdId' => 126, 'rrd_id' => '126', 'rr_dt' => '2026-01-10 10:00:00', 'sale_dt' => '2026-01-10 10:00:00', 'sa_name' => 'SKU-1', 'quantity' => 1, 'retail_price_withdisc_rub' => 1125.00, 'ppvz_for_pay' => 680.99, 'acquiring_fee' => 27.76]], JSON_THROW_ON_ERROR);
        $adapter = $this->createAdapter(new MockHttpClient([new MockResponse($payload, ['http_code' => 200]), new MockResponse('', ['http_code' => 204])]));
        $costs = $adapter->fetchCosts($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        self::assertCount(1, $costs);
        self::assertSame('wb_commission', $costs[0]->categoryCode);
        self::assertSame('416.25', $costs[0]->amount);
        self::assertSame('wb:126:wb_commission', $costs[0]->externalId);
    }

    private function createAdapter(MockHttpClient $http): WildberriesAdapter
    {
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        return new WildberriesAdapter($http, $repo, new NullLogger(), new WbSalesReportRowNormalizer(), new WbFinanceSalesReportClient($http));
    }

    private function company(): Company { return $this->createMock(Company::class); }
    private function connection(): MarketplaceConnection
    {
        $connection = $this->createMock(MarketplaceConnection::class);
        $connection->method('getApiKey')->willReturn('token');
        return $connection;
    }
}
