<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Wildberries;

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

final class WbFinanceSalesReportClientTest extends TestCase
{
    public function test200WithOneRowDoesNotTriggerSecondRequest(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;
            return new MockResponse('[{"rrdId":10}]', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, new MockClock());
        $rows = $client->fetchDetailed('token', '2026-01-01', '2026-01-01');

        self::assertCount(1, $rows);
        self::assertSame(1, $calls);
    }

    public function test204ReturnsAccumulatedRows(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient([
            new MockResponse('', ['http_code' => 204]),
        ]), new MockClock());

        self::assertSame([], $client->fetchDetailed('token', '2026-01-01', '2026-01-01'));
    }

    public function test429MappedToRateLimitException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429])), new MockClock());

        $this->expectException(MarketplaceRateLimitException::class);
        $client->fetchDetailed('token', '2026-01-01', '2026-01-01');
    }

    public function test401And403MappedToAuthException(): void
    {
        $client401 = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 401])), new MockClock());
        try {
            $client401->fetchDetailed('token', '2026-01-01', '2026-01-01');
            self::fail('Expected MarketplaceAuthException for 401');
        } catch (MarketplaceAuthException) {
            self::assertTrue(true);
        }

        $client403 = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 403])), new MockClock());
        $this->expectException(MarketplaceAuthException::class);
        $client403->fetchDetailed('token', '2026-01-01', '2026-01-01');
    }

    public function test400MappedToBadRequestException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"bad"}', ['http_code' => 400])), new MockClock());

        $this->expectException(MarketplaceBadRequestException::class);
        $client->fetchDetailed('token', '2026-01-01', '2026-01-01');
    }

    public function testInvalidJsonMappedToInvalidApiResponseException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{invalid', ['http_code' => 200])), new MockClock());

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $client->fetchDetailed('token', '2026-01-01', '2026-01-01');
    }

    public function testCountEqualPageSizeUsesDelayBeforeNextRequest(): void
    {
        $rows = array_fill(0, 100000, ['rrdId' => 10]);
        $rows[99999] = ['rrdId' => 20];

        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, $rows): MockResponse {
            $captured[] = $options['json'] ?? null;
            return match (count($captured)) {
                1 => new MockResponse((string) json_encode($rows, JSON_THROW_ON_ERROR), ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => 204]),
            };
        });

        $clock = new MockClock('2026-01-01T00:00:00Z');
        $client = new WbFinanceSalesReportClient($http, $clock);

        $client->fetchDetailed('token', '2026-01-01', '2026-01-01');

        self::assertSame(20, $captured[1]['rrdId']);
        self::assertSame('2026-01-01T00:01:01+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testProbeAccessUsesFinancePingEndpoint(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];
            return new MockResponse('', ['http_code' => 200]);
        });

        $client = new WbFinanceSalesReportClient($http, new MockClock());
        self::assertTrue($client->probeAccess('token'));
        self::assertSame('GET', $captured[0]);
        self::assertStringEndsWith('/ping', $captured[1]);
        self::assertStringNotContainsString('/api/finance/v1/sales-reports/detailed', $captured[1]);
        self::assertArrayNotHasKey('json', $captured[2]);
    }

    public function testProbeAccessReturnsTrueFor200StatusOk(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"TS":"2024-08-16T11:19:05+03:00","Status":"OK"}', ['http_code' => 200])), new MockClock());
        self::assertTrue($client->probeAccess('token'));
    }

    public function testProbeAccessReturnsFalseFor401(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 401])), new MockClock());
        self::assertFalse($client->probeAccess('token'));
    }

    public function testProbeAccessReturnsFalseFor403(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 403])), new MockClock());
        self::assertFalse($client->probeAccess('token'));
    }

    public function testProbeAccess429ThrowsRateLimitException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429])), new MockClock());
        $this->expectException(MarketplaceRateLimitException::class);
        $client->probeAccess('token');
    }

    public function testProbeAccess5xxThrowsTemporaryApiException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"server"}', ['http_code' => 500])), new MockClock());
        $this->expectException(MarketplaceTemporaryApiException::class);
        $client->probeAccess('token');
    }

    public function testProbeAccessTransportErrorThrowsTemporaryApiException(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('network');
        });
        $client = new WbFinanceSalesReportClient($http, new MockClock());
        $this->expectException(MarketplaceTemporaryApiException::class);
        $client->probeAccess('token');
    }
}
