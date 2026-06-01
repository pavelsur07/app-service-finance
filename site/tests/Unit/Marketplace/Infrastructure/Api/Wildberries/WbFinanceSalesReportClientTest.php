<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Application\Service\WbFinanceCooldownStorageInterface;
use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
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
            if (null === $payload && isset($options['body']) && is_string($options['body']) && '' !== $options['body']) {
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

    public function testFetchDetailedDayThrowsBeforeHttpRequestWhenLocalBucketIsBusy(): void
    {
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());

        self::assertSame([], $client->fetchDetailedDay('same-connection', 'token', new \DateTimeImmutable('2026-01-15')));

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->fetchDetailedDay('same-connection', 'token', new \DateTimeImmutable('2026-01-15'));
        } finally {
            self::assertSame(1, $requestCount);
        }
    }

    public function testFetchDetailedDayUsesDifferentLimiterBucketsForDifferentConnectionIdsWhenSellerBucketMissing(): void
    {
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $clock = new MockClock('@'.time());
        $startTimestamp = $clock->now()->getTimestamp();
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter($clock));

        self::assertSame([], $client->fetchDetailedDay('connection-a', 'same-token', new \DateTimeImmutable('2026-01-15')));
        self::assertSame([], $client->fetchDetailedDay('connection-b', 'same-token', new \DateTimeImmutable('2026-01-15')));

        self::assertSame(2, $requestCount);
        self::assertSame($startTimestamp, $clock->now()->getTimestamp());
    }

    public function testFetchDetailedDayUsesConnectionBucketsEvenWhenSellerBucketsAreProvided(): void
    {
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $clock = new MockClock('@'.time());
        $startTimestamp = $clock->now()->getTimestamp();
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter($clock));

        self::assertSame([], $client->fetchDetailedDay('connection-a', 'token-a', new \DateTimeImmutable('2026-01-15'), 'seller-a'));
        self::assertSame([], $client->fetchDetailedDay('connection-b', 'token-b', new \DateTimeImmutable('2026-01-15'), 'seller-b'));
        self::assertSame(2, $requestCount);
        self::assertSame($startTimestamp, $clock->now()->getTimestamp());
    }

    public function testFetchDetailedDayPageReturnsContinuationCursorForFullPage(): void
    {
        $rows = array_fill(0, 100000, ['rrdId' => 10]);
        $rows[99999] = ['rrdId' => 20];

        $captured = [];
        $requestCount = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestCount, &$captured, $rows): MockResponse {
            ++$requestCount;
            $payload = $options['json'] ?? null;
            if (null === $payload && isset($options['body']) && is_string($options['body']) && '' !== $options['body']) {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            }
            $captured[] = $payload;

            return match ($requestCount) {
                1 => new MockResponse((string) json_encode($rows, JSON_THROW_ON_ERROR), ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => 204]),
            };
        });

        $clock = new MockClock('@'.time());
        $startTimestamp = $clock->now()->getTimestamp();
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter($clock));

        $page = $client->fetchDetailedDayPage('00000000-0000-0000-0000-000000000001', 'token', new \DateTimeImmutable('2026-01-15'), 0);

        self::assertCount(100000, $page->rows);
        self::assertTrue($page->hasNextPage);
        self::assertSame(20, $page->nextRrdId);
        self::assertSame(1, $requestCount);
        self::assertSame(0, $captured[0]['rrdId']);
        self::assertSame($startTimestamp, $clock->now()->getTimestamp());
    }

    public function testHasAnyDataForConnectionThrowsBeforeHttpRequestWhenLocalBucketIsBusy(): void
    {
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('[{"rrdId":1}]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter());

        self::assertTrue($client->hasAnyDataForConnection('same-connection', 'token', '2026-01-01', '2026-01-31'));

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->hasAnyDataForConnection('same-connection', 'token', '2026-01-01', '2026-01-31');
        } finally {
            self::assertSame(1, $requestCount);
        }
    }

    public function testHasAnyDataForConnectionDelegatesToHasAnyDataLogic(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $payload = $options['json'] ?? null;
            if (null === $payload && isset($options['body']) && is_string($options['body']) && '' !== $options['body']) {
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

    public function testHasAnyDataForConnectionStoresCooldownAfterRemote429AndSkipsNextHttpRequest(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('{"error":"rate"}', ['http_code' => 429, 'response_headers' => ['retry-after: 17']]);
        });
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter(null, $storage));

        try {
            $client->hasAnyDataForConnection('connection-id', 'token', '2026-02-01', '2026-02-03', 'seller-has-any');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(17, $e->getRetryAfter());
        }

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->hasAnyDataForConnection('connection-id', 'token', '2026-02-01', '2026-02-03', 'seller-has-any');
        } finally {
            self::assertSame(1, $requestCount);
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-id'));
            self::assertNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:seller-has-any'));
        }
    }

    public function testConnectionRemote429CreatesConnectionCooldownWithoutGlobalCooldown(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $connectionId = '6eada2b7-b453-4c33-a92a-e7dce52e291c';
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429, 'response_headers' => ['retry-after: 17']])),
            $this->createRateLimiter(new MockClock('2026-01-01T00:00:00Z'), $storage),
        );

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->fetchDetailedForConnection($connectionId, 'token', '2026-01-01', '2026-01-01');
        } finally {
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:'.$connectionId));
            self::assertNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:global'));
        }
    }

    public function testSecondConnectionIsNotBlockedByFirstConnectionCooldown(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $connectionA = '6eada2b7-b453-4c33-a92a-e7dce52e291c';
        $connectionB = '50bb10aa-4659-41fc-887b-f635de795839';
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('{"error":"rate"}', ['http_code' => 429, 'response_headers' => ['retry-after: 17']]);
        });
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter(new MockClock('2026-01-01T00:00:00Z'), $storage));

        try {
            $client->fetchDetailedForConnection($connectionA, 'token-a', '2026-01-01', '2026-01-01');
            self::fail('Expected first connection to be rate limited');
        } catch (MarketplaceRateLimitException) {
        }

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->fetchDetailedForConnection($connectionB, 'token-b', '2026-01-01', '2026-01-01');
        } finally {
            self::assertSame(2, $requestCount);
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:'.$connectionA));
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:'.$connectionB));
            self::assertNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:global'));
        }
    }

    public function testLegacyFetchDetailedUsesGlobalCooldownFallback(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429, 'response_headers' => ['retry-after: 17']])),
            $this->createRateLimiter(new MockClock('2026-01-01T00:00:00Z'), $storage),
        );

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->fetchDetailed('token', '2026-01-01', '2026-01-01');
        } finally {
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:global'));
        }
    }

    public function testLegacyHasAnyDataUsesGlobalCooldownFallback(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429, 'response_headers' => ['retry-after: 17']])),
            $this->createRateLimiter(new MockClock('2026-01-01T00:00:00Z'), $storage),
        );

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->hasAnyData('token', '2026-01-01', '2026-01-01');
        } finally {
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:global'));
        }
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
                'retry-after: 17',
            ],
        ])), $this->createRateLimiter());

        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(17, $e->getRetryAfter());
        }
    }


    public function testRemote429RetryAfterSecondsSetsCooldownFromNow(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $clock = new MockClock('2026-01-01T12:00:00Z');
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', [
                'http_code' => 429,
                'response_headers' => ['retry-after: 60'],
            ])),
            $this->createRateLimiter($clock, $storage),
        );

        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(60, $e->getRetryAfter());
        }

        self::assertSame(
            $clock->now()->modify('+60 seconds')->getTimestamp(),
            $storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-id'),
        );
    }

    public function testRemote429XRateLimitRetryRelativeSecondsSetsCooldownFromNow(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $clock = new MockClock('2026-01-01T12:00:00Z');
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', [
                'http_code' => 429,
                'response_headers' => ['x-ratelimit-retry: 60'],
            ])),
            $this->createRateLimiter($clock, $storage),
        );

        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(60, $e->getRetryAfter());
        }

        self::assertSame(
            $clock->now()->modify('+60 seconds')->getTimestamp(),
            $storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-id'),
        );
    }

    public function testRemote429XRateLimitRetryUnixTimestampSetsCooldownToTimestamp(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $clock = new MockClock('2026-01-01T12:00:00Z');
        $retryAt = $clock->now()->modify('+75 seconds')->getTimestamp();
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', [
                'http_code' => 429,
                'response_headers' => ['x-ratelimit-retry: '.$retryAt],
            ])),
            $this->createRateLimiter($clock, $storage),
        );

        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(75, $e->getRetryAfter());
        }

        self::assertSame($retryAt, $storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-id'));
    }

    public function testRemote429InvalidRateLimitHeaderUsesShortFallback(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $clock = new MockClock('2026-01-01T12:00:00Z');
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', [
                'http_code' => 429,
                'response_headers' => ['x-ratelimit-retry: not-a-date'],
            ])),
            $this->createRateLimiter($clock, $storage),
        );

        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertNull($e->getRetryAfter());
        }

        self::assertSame(
            $clock->now()->modify('+85 seconds')->getTimestamp(),
            $storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-id'),
        );
    }


    public function testRemote429RetryAfterTakesPriorityOverLongResetTimestamp(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $clock = new MockClock('2026-01-01T12:00:00Z');
        $longResetAt = $clock->now()->setTime(21, 15)->getTimestamp();
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', [
                'http_code' => 429,
                'response_headers' => [
                    'retry-after: 60',
                    'x-ratelimit-reset: '.$longResetAt,
                ],
            ])),
            $this->createRateLimiter($clock, $storage),
        );

        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(60, $e->getRetryAfter());
        }

        self::assertSame(
            $clock->now()->modify('+60 seconds')->getTimestamp(),
            $storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:connection:connection-id'),
        );
    }

    public function testRemote429LogsRawRateLimitHeadersAndCalculatedCooldown(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $clock = new MockClock('2026-01-01T12:00:00Z');
        $logger = new InMemoryLogger();
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', [
                'http_code' => 429,
                'response_headers' => [
                    'retry-after: 60',
                    'x-ratelimit-limit: 1',
                    'x-ratelimit-remaining: 0',
                ],
            ])),
            $this->createRateLimiter($clock, $storage),
            $logger,
        );

        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException) {
        }

        $record = $logger->firstRecordByMessage('WB finance remote rate limit response.');
        self::assertNotNull($record);
        self::assertSame('60', $record['context']['retry_after'] ?? null);
        self::assertSame('1', $record['context']['x_ratelimit_limit'] ?? null);
        self::assertSame('0', $record['context']['x_ratelimit_remaining'] ?? null);
        self::assertSame('2026-01-01T12:00:00+00:00', $record['context']['server_time'] ?? null);
        self::assertSame('2026-01-01T12:01:00+00:00', $record['context']['calculated_cooldown_until'] ?? null);
        self::assertJson((string) ($record['context']['response_headers_json'] ?? ''));
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

    public function testCountEqualPageSizeThrowsBeforeNextRequestWhenBucketIsBusy(): void
    {
        $rows = array_fill(0, 100000, ['rrdId' => 10]);
        $rows[99999] = ['rrdId' => 20];

        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, $rows): MockResponse {
            $payload = $options['json'] ?? null;

            if (null === $payload && isset($options['body']) && is_string($options['body']) && '' !== $options['body']) {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            }

            $captured[] = $payload;

            return match (count($captured)) {
                1 => new MockResponse((string) json_encode($rows, JSON_THROW_ON_ERROR), ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => 204]),
            };
        });

        $clock = new MockClock('@'.time());
        $startTimestamp = $clock->now()->getTimestamp();
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter($clock));

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->fetchDetailedForConnection('connection-id', 'token', '2026-01-01', '2026-01-01');
        } finally {
            self::assertCount(1, $captured);
            self::assertSame(0, $captured[0]['rrdId'] ?? null);
            self::assertSame($startTimestamp, $clock->now()->getTimestamp());
        }
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

    public function testProbeAccessRespectsSellerCooldownWithoutHttpRequest(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $storage->setUntilTimestamp('wb_finance:sales_reports:cooldown:seller-1', $clock->now()->modify('+60 seconds')->getTimestamp(), 60);
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('{"Status":"OK"}', ['http_code' => 200]);
        });
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter($clock, $storage));

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->probeAccess('token', 'seller-1');
        } finally {
            self::assertSame(0, $requestCount);
        }
    }

    public function testProbeAccessRemote429SetsSellerCooldownAndNextProbeSkipsHttp(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $requestCount = 0;
        $http = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('{"error":"rate"}', ['http_code' => 429, 'response_headers' => ['retry-after: 17']]);
        });
        $client = new WbFinanceSalesReportClient($http, $this->createRateLimiter(new MockClock('2026-01-01T00:00:00Z'), $storage));

        try {
            $client->probeAccess('token', 'seller-1');
            self::fail('Expected MarketplaceRateLimitException');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(17, $e->getRetryAfter());
        }

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->probeAccess('token', 'seller-1');
        } finally {
            self::assertSame(1, $requestCount);
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:seller-1'));
        }
    }

    public function testProbeAccessRemote429UsesGlobalCooldownFallback(): void
    {
        $storage = new InMemoryWbFinanceCooldownStorage();
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429, 'response_headers' => ['retry-after: 17']])),
            $this->createRateLimiter(new MockClock('2026-01-01T00:00:00Z'), $storage),
        );

        $this->expectException(MarketplaceRateLimitException::class);
        try {
            $client->probeAccess('token', null);
        } finally {
            self::assertNotNull($storage->getUntilTimestamp('wb_finance:sales_reports:cooldown:global'));
        }
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

    private function createRateLimiter(?MockClock $clock = null, ?WbFinanceCooldownStorageInterface $storage = null): WbFinanceRateLimiter
    {
        $factory = new RateLimiterFactory(
            [
                'id' => 'wb_finance',
                'policy' => 'token_bucket',
                'limit' => 1,
                'rate' => ['interval' => '1 second', 'amount' => 1],
            ],
            new InMemoryStorage(),
        );

        return new WbFinanceRateLimiter($factory, $clock ?? new MockClock(), null, $storage);
    }
}


final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return array{level: mixed, message: string, context: array<string, mixed>}|null */
    public function firstRecordByMessage(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($message === $record['message']) {
                return $record;
            }
        }

        return null;
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
