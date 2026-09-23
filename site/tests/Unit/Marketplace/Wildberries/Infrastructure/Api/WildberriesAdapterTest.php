<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Wildberries\Infrastructure\Api;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Wildberries\Application\Service\WbFinanceCooldownStorageInterface;
use App\Marketplace\Wildberries\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Wildberries\Infrastructure\Api\WbFinanceSalesReportClient;
use App\Marketplace\Wildberries\Infrastructure\Api\WildberriesAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WildberriesAdapterTest extends TestCase
{
    public function testAuthenticateUsesProbeAccess(): void
    {
        $http = new MockHttpClient(new MockResponse('{"Status":"OK"}', ['http_code' => 200]));
        $adapter = $this->createAdapter($http);
        self::assertTrue($adapter->authenticate($this->company()));
    }

    public function testAuthenticatePassesConnectionAwareBucketToProbeAccess(): void
    {
        $storage = new AdapterCooldownStorageFake();
        $http = new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429, 'response_headers' => ['x-ratelimit-retry: 17']]));
        $adapter = $this->createAdapter($http, $storage);

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $adapter->authenticate($this->company());
        } finally {
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-id'));
            self::assertNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:global'));
        }
    }

    private function createAdapter(MockHttpClient $http, ?WbFinanceCooldownStorageInterface $storage = null): WildberriesAdapter
    {
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        return new WildberriesAdapter($repo, new WbFinanceSalesReportClient($http, $this->createRateLimiter($storage)), new \App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec($this->createMock(\App\Shared\Security\Contract\FieldEncryptionServiceInterface::class), $this->createMock(\App\Shared\Security\Contract\SecretRotationServiceInterface::class)));
    }

    private function company(): Company
    {
        return $this->createMock(Company::class);
    }

    private function connection(): MarketplaceConnection
    {
        $connection = $this->createMock(MarketplaceConnection::class);
        $connection->method('getId')->willReturn('connection-id');
        $connection->method('getApiKey')->willReturn('token');

        return $connection;
    }

    private function createRateLimiter(?WbFinanceCooldownStorageInterface $storage = null): WbFinanceRateLimiter
    {
        return new WbFinanceRateLimiter(new RateLimiterFactory(['id' => 'wb_finance', 'policy' => 'token_bucket', 'limit' => 1, 'rate' => ['interval' => '61 seconds', 'amount' => 1]], new InMemoryStorage()), new MockClock('2026-01-01T00:00:00Z'), null, $storage);
    }
}

final class AdapterCooldownStorageFake implements WbFinanceCooldownStorageInterface
{
    /** @var array<string, int> */
    private array $values = [];

    public function getUntilTimestamp(string $key): ?int
    {
        return $this->values[$key] ?? null;
    }

    public function setUntilTimestamp(string $key, int $untilTimestamp, int $ttlSeconds): void
    {
        $this->values[$key] = max($this->values[$key] ?? 0, $untilTimestamp);
    }
}
