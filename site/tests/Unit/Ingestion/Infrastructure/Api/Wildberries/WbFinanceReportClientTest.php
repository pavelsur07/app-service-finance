<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Api\Wildberries;

use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Infrastructure\Api\Wildberries\WbCredentialProviderInterface;
use App\Ingestion\Infrastructure\Api\Wildberries\WbFinanceReportClient;
use App\Marketplace\Application\Service\WbFinanceCooldownStorageInterface;
use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinanceReportClientTest extends TestCase
{
    public function testFetchDetailedDayPageSendsExpectedRequestAndParsesRows(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions): MockResponse {
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('[{"rrdId":10,"docTypeName":"Продажа"}]', ['http_code' => 200]);
        });

        $client = $this->client($http);
        $page = $client->fetchDetailedDayPage(
            '0192f0c2-0000-7000-8000-000000000001',
            'connection-1',
            new \DateTimeImmutable('2026-06-20T12:00:00+03:00'),
            0,
            100,
        );

        self::assertSame('https://finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed', $capturedUrl);
        $requestPayload = $capturedOptions['json'] ?? json_decode((string) ($capturedOptions['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-06-20', $requestPayload['dateFrom'] ?? null);
        self::assertSame('2026-06-20', $requestPayload['dateTo'] ?? null);
        self::assertSame(100, $requestPayload['limit'] ?? null);
        self::assertSame(0, $requestPayload['rrdId'] ?? null);
        self::assertSame('daily', $requestPayload['period'] ?? null);
        self::assertSame(['Authorization: wb-token'], $capturedOptions['normalized_headers']['authorization'] ?? null);
        self::assertSame([['rrdId' => 10, 'docTypeName' => 'Продажа']], $page->rows);
        self::assertSame(10, $page->nextRrdId);
        self::assertFalse($page->hasMore);
    }

    public function testFullPageReturnsHasMore(): void
    {
        $http = new MockHttpClient(new MockResponse('[{"rrdId":11}]', ['http_code' => 200]));
        $page = $this->client($http)->fetchDetailedDayPage(
            '0192f0c2-0000-7000-8000-000000000001',
            'connection-1',
            new \DateTimeImmutable('2026-06-20'),
            10,
            1,
        );

        self::assertTrue($page->hasMore);
        self::assertSame(11, $page->nextRrdId);
    }

    public function testNoContentReturnsEmptyPage(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 204]));
        $page = $this->client($http)->fetchDetailedDayPage(
            '0192f0c2-0000-7000-8000-000000000001',
            'connection-1',
            new \DateTimeImmutable('2026-06-20'),
            0,
        );

        self::assertSame([], $page->rows);
        self::assertNull($page->nextRrdId);
        self::assertFalse($page->hasMore);
    }

    public function testAuthFailureIsClassified(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 403]));

        $this->expectException(ConnectorAuthException::class);

        $this->client($http)->fetchDetailedDayPage(
            '0192f0c2-0000-7000-8000-000000000001',
            'connection-1',
            new \DateTimeImmutable('2026-06-20'),
            0,
        );
    }

    public function testRemoteRateLimitUsesRetryHeader(): void
    {
        $clock = new MockClock('2026-06-22T00:00:00+00:00');
        $storage = new InMemoryWbFinanceCooldownStorage();
        $http = new MockHttpClient(new MockResponse('{"error":"rate"}', [
            'http_code' => 429,
            'response_headers' => ['x-ratelimit-retry' => '12'],
        ]));

        try {
            $this->client($http, $storage, $clock)->fetchDetailedDayPage(
                '0192f0c2-0000-7000-8000-000000000001',
                'connection-1',
                new \DateTimeImmutable('2026-06-20'),
                0,
            );
            self::fail('Expected rate limit exception.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(12, $exception->retryAfterSeconds());
            self::assertSame(
                $clock->now()->modify('+12 seconds')->getTimestamp(),
                $storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-1'),
            );
        }
    }

    public function testSharedCooldownPreventsRequestForConnection(): void
    {
        $clock = new MockClock('2026-06-22T00:00:00+00:00');
        $storage = new InMemoryWbFinanceCooldownStorage();
        $storage->setUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-1', $clock->now()->modify('+30 seconds')->getTimestamp(), 30);
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $this->expectException(ConnectorRateLimitedException::class);
        try {
            $this->client($http, $storage, $clock)->fetchDetailedDayPage(
                '0192f0c2-0000-7000-8000-000000000001',
                'connection-1',
                new \DateTimeImmutable('2026-06-20'),
                0,
            );
        } finally {
            self::assertSame(0, $requestCount);
        }
    }

    public function testLocalRateLimitPreventsSecondRequestForSameConnection(): void
    {
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('[]', ['http_code' => 200]);
        });
        $client = $this->client($http);

        $client->fetchDetailedDayPage(
            '0192f0c2-0000-7000-8000-000000000001',
            'connection-1',
            new \DateTimeImmutable('2026-06-20'),
            0,
        );

        $this->expectException(ConnectorRateLimitedException::class);
        try {
            $client->fetchDetailedDayPage(
                '0192f0c2-0000-7000-8000-000000000001',
                'connection-1',
                new \DateTimeImmutable('2026-06-20'),
                0,
            );
        } finally {
            self::assertSame(1, $requestCount);
        }
    }

    private function client(
        MockHttpClient $http,
        ?WbFinanceCooldownStorageInterface $cooldownStorage = null,
        ?MockClock $clock = null,
    ): WbFinanceReportClient
    {
        $clock ??= new MockClock('2026-06-22T00:00:00+00:00');

        return new WbFinanceReportClient(
            $http,
            new class implements WbCredentialProviderInterface {
                public function read(string $companyId, string $connectionRef): array
                {
                    return ['api_key' => 'wb-token'];
                }
            },
            new WbFinanceRateLimiter(new RateLimiterFactory(
                [
                    'id' => 'wb_finance',
                    'policy' => 'fixed_window',
                    'limit' => 1,
                    'interval' => '70 seconds',
                ],
                new InMemoryStorage(),
            ), $clock, null, $cooldownStorage),
            $clock,
            new NullLogger(),
        );
    }
}

final class InMemoryWbFinanceCooldownStorage implements WbFinanceCooldownStorageInterface
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
