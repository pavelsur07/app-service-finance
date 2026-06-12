<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Service\Integration;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\OzonAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonAdapterDateParsingTest extends TestCase
{
    /**
     * M3: операция с некорректной/отсутствующей operation_date пропускается
     * (логируется), а не роняет обработку всего батча.
     */
    public function testFetchSalesSkipsOperationWithInvalidOperationDate(): void
    {
        $payload = json_encode([
            'result' => [
                'operations' => [
                    ['type' => 'orders', 'accruals_for_sale' => 100, 'operation_id' => 1, 'operation_date' => 'not-a-date', 'items' => [['sku' => 'A']], 'posting' => ['posting_number' => 'P1']],
                    ['type' => 'orders', 'accruals_for_sale' => 200, 'operation_id' => 2, 'operation_date' => '2026-01-15 10:00:00', 'items' => [['sku' => 'B']], 'posting' => ['posting_number' => 'P2']],
                ],
                'page_count' => 1,
            ],
        ], \JSON_THROW_ON_ERROR);

        $adapter = $this->createAdapter(new MockHttpClient(new MockResponse($payload, ['http_code' => 200])));

        $sales = $adapter->fetchSales(
            $this->createMock(Company::class),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        self::assertCount(1, $sales);
        self::assertSame('P2', $sales[0]->externalOrderId);
    }

    private function createAdapter(MockHttpClient $http): OzonAdapter
    {
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        return new OzonAdapter($http, $repo, new NullLogger());
    }

    private function connection(): MarketplaceConnection
    {
        $connection = $this->createMock(MarketplaceConnection::class);
        $connection->method('getId')->willReturn('connection-id');
        $connection->method('getClientId')->willReturn('client-id');
        $connection->method('getApiKey')->willReturn('api-key');

        return $connection;
    }
}
