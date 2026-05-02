<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Service\Integration;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class WildberriesAdapterTest extends TestCase
{
    public function testReturnsEmptyArrayForValidEmptyList(): void
    {
        $adapter = $this->createAdapter(new MockResponse('[]', ['http_code' => 200]));

        self::assertSame([], $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
    }

    public function testThrowsRateLimitExceptionFor429(): void
    {
        $adapter = $this->createAdapter(new MockResponse('{"error":"too many"}', ['http_code' => 429, 'response_headers' => ['X-Ratelimit-Retry: 120', 'Retry-After: 15']]));

        try {
            $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
            self::fail('Expected MarketplaceRateLimitException was not thrown');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertSame(120, $e->getRetryAfter());
        }
    }


    public function testReturnsEmptyArrayFor204NoData(): void
    {
        $adapter = $this->createAdapter(new MockResponse('', ['http_code' => 204]));

        self::assertSame([], $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
    }

    public function testProbeReturnsFalseFor204NoData(): void
    {
        $adapter = $this->createAdapter(new MockResponse('', ['http_code' => 204]));

        self::assertFalse($adapter->hasRawReportData($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
    }

    public function testRateLimitUsesXRateLimitResetWhenRetryMissing(): void
    {
        $adapter = $this->createAdapter(new MockResponse('{"error":"too many"}', ['http_code' => 429, 'response_headers' => ['X-Ratelimit-Reset: 300']]));

        try {
            $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
            self::fail('Expected MarketplaceRateLimitException was not thrown');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(300, $e->getRetryAfter());
        }
    }

    public function testRateLimitUsesResetAsTimestampWhenValueLooksLikeUnixTime(): void
    {
        $resetTimestamp = time() + 300;
        $adapter = $this->createAdapter(new MockResponse('{"error":"too many"}', ['http_code' => 429, 'response_headers' => ['X-Ratelimit-Reset: '.$resetTimestamp]]));

        try {
            $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
            self::fail('Expected MarketplaceRateLimitException was not thrown');
        } catch (MarketplaceRateLimitException $e) {
            self::assertGreaterThanOrEqual(299, $e->getRetryAfter());
            self::assertLessThanOrEqual(300, $e->getRetryAfter());
        }
    }

    public function testRateLimitAllowsZeroRetryAfterValue(): void
    {
        $adapter = $this->createAdapter(new MockResponse('{"error":"too many"}', ['http_code' => 429, 'response_headers' => ['X-Ratelimit-Retry: 0']]));

        try {
            $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
            self::fail('Expected MarketplaceRateLimitException was not thrown');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(0, $e->getRetryAfter());
        }
    }

    public function testThrowsAuthExceptionFor401And403(): void
    {
        $adapter401 = $this->createAdapter(new MockResponse('', ['http_code' => 401]));
        $adapter403 = $this->createAdapter(new MockResponse('', ['http_code' => 403]));

        try {
            $adapter401->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
            self::fail('Expected MarketplaceAuthException for 401 was not thrown');
        } catch (MarketplaceAuthException) {
            self::assertTrue(true);
        }

        $this->expectException(MarketplaceAuthException::class);
        $adapter403->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    public function testThrowsTemporaryExceptionFor500(): void
    {
        $adapter = $this->createAdapter(new MockResponse('{"error":"server"}', ['http_code' => 500]));

        $this->expectException(MarketplaceTemporaryApiException::class);
        $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    public function testReturnsEmptyArrayForEmptyBodyOn200(): void
    {
        $adapter = $this->createAdapter(new MockResponse('', ['http_code' => 200]));

        self::assertSame([], $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
    }


    public function testThrowsInvalidResponseExceptionForScalarListItems(): void
    {
        $adapter = $this->createAdapter(new MockResponse('[1,2,3]', ['http_code' => 200]));

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    public function testThrowsInvalidResponseExceptionForInvalidJson(): void
    {
        $adapter = $this->createAdapter(new MockResponse('{invalid', ['http_code' => 200]));

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    public function testProbeReturnsFalseForEmptyListAndUsesLightweightQuery(): void
    {
        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['query'];
            return new MockResponse('[]', ['http_code' => 200]);
        });
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());
        $adapter = new WildberriesAdapter($client, $repo, new NullLogger());

        self::assertFalse($adapter->hasRawReportData($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
        self::assertSame(1, $captured['limit']);
        self::assertSame('daily', $captured['period']);
        self::assertSame(0, $captured['rrdid']);
    }

    public function testProbeReturnsTrueForNonEmptyList(): void
    {
        $adapter = $this->createAdapter(new MockResponse('[{"id":1}]', ['http_code' => 200]));
        self::assertTrue($adapter->hasRawReportData($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
    }

    public function testFetchRawReportThrowsTemporaryExceptionOnTransportErrorWhileReadingBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn([]);
        $response->method('getContent')->willThrowException($this->createMock(TransportExceptionInterface::class));

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        $adapter = new WildberriesAdapter($client, $repo, new NullLogger());

        $this->expectException(MarketplaceTemporaryApiException::class);
        $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    public function testLogsWarningForPayloadLargerThanOneHundredThousandWithoutThrowing(): void
    {
        $payload = '['.str_repeat('{},', 100_000).'{}]';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('WB payload too large', ['count' => 100_001]);

        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        $adapter = new WildberriesAdapter(new MockHttpClient(new MockResponse($payload, ['http_code' => 200])), $repo, $logger);

        $decoded = $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));

        self::assertCount(100_001, $decoded);
        self::assertIsArray($decoded);
    }

    public function testHasRawReportDataThrowsTemporaryExceptionOnTransportErrorWhileReadingBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn([]);
        $response->method('getContent')->willThrowException($this->createMock(TransportExceptionInterface::class));

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        $adapter = new WildberriesAdapter($client, $repo, new NullLogger());

        $this->expectException(MarketplaceTemporaryApiException::class);
        $adapter->hasRawReportData($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    private function createAdapter(MockResponse $response): WildberriesAdapter
    {
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        return new WildberriesAdapter(new MockHttpClient($response), $repo, new NullLogger());
    }

    private function company(): Company
    {
        return $this->createMock(Company::class);
    }

    private function connection(): MarketplaceConnection
    {
        $connection = $this->createMock(MarketplaceConnection::class);
        $connection->method('getApiKey')->willReturn('token');

        return $connection;
    }
}
