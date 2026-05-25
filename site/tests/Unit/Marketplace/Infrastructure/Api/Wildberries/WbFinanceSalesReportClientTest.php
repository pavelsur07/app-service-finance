<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinanceSalesReportClientTest extends TestCase
{
    public function test200WithOneRowDoesNotTriggerSecondRequest(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options['json'] ?? json_decode((string) ($options['body'] ?? 'null'), true);
            return new MockResponse('[{"rrdId":10}]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());
        $rows = $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');

        self::assertCount(1, $rows);
        self::assertCount(1, $captured);
        self::assertSame(0, $captured[0]['rrdId'] ?? null);
    }


    public function testFetchDetailedDayUsesSameDateInRange(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $payload = $options['json'] ?? null;
            if ($payload === null && isset($options['body']) && is_string($options['body']) && '' !== $options['body']) {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            }
            $captured[] = $payload;

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());
        $client->fetchDetailedDay('conn-1', 'token', new \DateTimeImmutable('2026-01-15T12:00:00+03:00'));

        self::assertSame('2026-01-15', $captured[0]['dateFrom'] ?? null);
        self::assertSame('2026-01-15', $captured[0]['dateTo'] ?? null);
    }


    public function testFetchDetailedDayAppliesLocalRateLimitBeforeHttpRequest(): void
    {
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());

        self::assertSame([], $client->fetchDetailedDay('same-connection', 'token', new \DateTimeImmutable('2026-01-15')));
        self::assertSame([], $client->fetchDetailedDay('same-connection', 'token', new \DateTimeImmutable('2026-01-15')));
        self::assertSame(2, $requestCount);
    }

    public function testFetchDetailedDayUsesDifferentLimiterBucketsPerConnectionId(): void
    {
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());

        self::assertSame([], $client->fetchDetailedDay('connection-a', 'token', new \DateTimeImmutable('2026-01-15')));
        self::assertSame([], $client->fetchDetailedDay('connection-b', 'token', new \DateTimeImmutable('2026-01-15')));
        self::assertSame(2, $requestCount);
    }


    public function testFetchDetailedDayAppliesLocalRateLimitOnPaginationRequest(): void
    {
        $rows = array_fill(0, 100000, ['rrdId' => 10]);
        $rows[99999] = ['rrdId' => 20];

        $captured = [];
        $requestCount = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestCount, &$captured, $rows): MockResponse {
            ++$requestCount;
            $captured[] = $options['json'];

            return match ($requestCount) {
                1 => new MockResponse((string) json_encode($rows, JSON_THROW_ON_ERROR), ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => 204]),
            };
        });

        $clock = new MockClock('2026-01-01T00:00:00Z');
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter($clock));

        $data = $client->fetchDetailedDay('00000000-0000-0000-0000-000000000001', 'token', new \DateTimeImmutable('2026-01-15'));
        self::assertCount(100000, $data);
        self::assertSame(2, $requestCount);
        self::assertSame(0, $captured[0]['rrdId']);
        self::assertSame(20, $captured[1]['rrdId']);
        self::assertSame('2026-01-01T00:01:01+00:00', $clock->now()->format(DATE_ATOM));
    }


    public function testHasAnyDataForConnectionAppliesLocalRateLimitBeforeHttpRequest(): void
    {
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;
            return new MockResponse('[{"rrdId":1}]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());

        self::assertTrue($client->hasAnyDataForConnection('same-connection', 'token', '2026-01-01', '2026-01-31'));
        self::assertTrue($client->hasAnyDataForConnection('same-connection', 'token', '2026-01-01', '2026-01-31'));
        self::assertSame(2, $requestCount);
    }

    public function testHasAnyDataForConnectionDelegatesToHasAnyDataLogic(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $payload = $options['json'] ?? null;
            if ($payload === null && isset($options['body']) && is_string($options['body']) && '' !== $options['body']) {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            }
            $captured = [$method, $url, $payload];

            return new MockResponse('[{"rrdId":77}]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());

        self::assertTrue($client->hasAnyDataForConnection('connection-id', 'token', '2026-02-01', '2026-02-03'));
        self::assertSame('POST', $captured[0]);
        self::assertStringEndsWith('/api/finance/v1/sales-reports/detailed', $captured[1]);
        self::assertSame(1, $captured[2]['limit'] ?? null);
        self::assertSame('2026-02-01', $captured[2]['dateFrom'] ?? null);
        self::assertSame('2026-02-03', $captured[2]['dateTo'] ?? null);
    }
    public function test204ReturnsAccumulatedRows(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient([
            new MockResponse('', ['http_code' => 204]),
        ]), $this->createRateLimiter());

        self::assertSame([], $client->fetchDetailed('token', '2026-01-01', '2026-01-01'));
    }

    public function test429MappedToRateLimitException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429])), $this->createRateLimiter());

        $this->expectException(MarketplaceRateLimitException::class);
        $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
    }


    public function test429MappedToRateLimitExceptionWithRetryAfter(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"rate"}', [
            'http_code' => 429,
            'response_headers' => [
                'x-ratelimit-retry: 17',
            ],
        ])), $this->createRateLimiter());

        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(17, $e->getRetryAfter());
        }
    }

    public function test401And403MappedToAuthException(): void
    {
        $client401 = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 401])), $this->createRateLimiter());
        try {
            $client401->fetchDetailed('token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceAuthException for 401');
        } catch (MarketplaceAuthException) {
            self::assertTrue(true);
        }

        $client403 = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 403])), $this->createRateLimiter());
        $this->expectException(MarketplaceAuthException::class);
        $client403->fetchDetailed('token', '2026-01-01', '2026-01-01');
    }

    public function test400MappedToBadRequestException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"bad"}', ['http_code' => 400])), $this->createRateLimiter());

        $this->expectException(MarketplaceBadRequestException::class);
        $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
    }

    public function testInvalidJsonMappedToInvalidApiResponseException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{invalid', ['http_code' => 200])), $this->createRateLimiter());

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
    }

    public function testCountEqualPageSizeUsesDelayBeforeNextRequest(): void
    {
        $rows = array_fill(0, 100000, ['rrdId' => 10]);
        $rows[99999] = ['rrdId' => 20];

        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, $rows): MockResponse {
            $payload = $options['json'] ?? null;

            if ($payload === null && isset($options['body']) && is_string($options['body']) && '' !== $options['body']) {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            }

            $captured[] = $payload;
            return match (count($captured)) {
                1 => new MockResponse((string) json_encode($rows, JSON_THROW_ON_ERROR), ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => 204]),
            };
        });

        $clock = new MockClock('2026-01-01T00:00:00Z');
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter($clock));

        $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');

        self::assertIsArray($captured[1]);
        self::assertSame(20, $captured[1]['rrdId'] ?? null);
        self::assertSame('2026-01-01T00:01:01+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testProbeAccessUsesFinancePingEndpoint(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];
            return new MockResponse('', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());
        self::assertTrue($client->probeAccess('token'));
        self::assertSame('GET', $captured[0]);
        self::assertStringEndsWith('/ping', $captured[1]);
        self::assertStringNotContainsString('/api/finance/v1/sales-reports/detailed', $captured[1]);
        self::assertArrayNotHasKey('json', $captured[2]);
    }

    public function testProbeAccessReturnsTrueFor200StatusOk(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"TS":"2024-08-16T11:19:05+03:00","Status":"OK"}', ['http_code' => 200])), $this->createRateLimiter());
        self::assertTrue($client->probeAccess('token'));
    }

    public function testProbeAccessReturnsFalseFor401(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 401])), $this->createRateLimiter());
        self::assertFalse($client->probeAccess('token'));
    }

    public function testProbeAccessReturnsFalseFor403(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 403])), $this->createRateLimiter());
        self::assertFalse($client->probeAccess('token'));
    }

    public function testProbeAccess429ThrowsRateLimitException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429])), $this->createRateLimiter());
        $this->expectException(MarketplaceRateLimitException::class);
        $client->probeAccess('token');
    }

    public function testProbeAccess5xxThrowsTemporaryApiException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"server"}', ['http_code' => 500])), $this->createRateLimiter());
        $this->expectException(MarketplaceTemporaryApiException::class);
        $client->probeAccess('token');
    }

    public function testProbeAccessTransportErrorThrowsTemporaryApiException(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('network');
        });
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());
        $this->expectException(MarketplaceTemporaryApiException::class);
        $client->probeAccess('token');
    }
    private function createRateLimiter(?MockClock $clock = null): WbFinanceRateLimiter
    {
        $factory = new RateLimiterFactory(
            [
                'id' => 'wb_finance',
                'policy' => 'token_bucket',
                'limit' => 1,
                'rate' => ['interval' => '61 seconds', 'amount' => 1],
            ],
            new InMemoryStorage(),
        );

        return new WbFinanceRateLimiter($factory, $clock ?? new MockClock());
    }
}
