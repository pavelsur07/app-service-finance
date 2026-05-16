<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WbFinanceSalesReportClientTest extends TestCase
{
    public function testFetchDetailedPaginatesByRrdIdUntil204(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options['json'] ?? null;
            return match (count($captured)) {
                1 => new MockResponse('[{"rrdId":10},{"rrdId":20}]', ['http_code' => 200]),
                2 => new MockResponse('[{"rrdId":30}]', ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => 204]),
            };
        });

        $client = new WbFinanceSalesReportClient($http);

        $rows = $client->fetchDetailed('token', '2026-01-01', '2026-01-01');

        self::assertCount(3, $rows);
        self::assertSame(0, $captured[0]['rrdId']);
        self::assertSame(20, $captured[1]['rrdId']);
        self::assertSame(30, $captured[2]['rrdId']);
    }

    public function test429MappedToRateLimitException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429])));

        $this->expectException(MarketplaceRateLimitException::class);
        $client->fetchDetailed('token', '2026-01-01', '2026-01-01');
    }

    public function testInvalidJsonMappedToInvalidApiResponseException(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('{invalid', ['http_code' => 200])));

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $client->fetchDetailed('token', '2026-01-01', '2026-01-01');
    }


    public function testProbeAccessReturnsTrueFor200And204(): void
    {
        $client200 = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('[]', ['http_code' => 200])));
        self::assertTrue($client200->probeAccess('token'));

        $client204 = new WbFinanceSalesReportClient(new MockHttpClient(new MockResponse('', ['http_code' => 204])));
        self::assertTrue($client204->probeAccess('token'));
    }

    public function testThrowsWhenRrdIdDoesNotIncrease(): void
    {
        $client = new WbFinanceSalesReportClient(new MockHttpClient([
            new MockResponse('[{"rrdId":10}]', ['http_code' => 200]),
            new MockResponse('[{"rrdId":10}]', ['http_code' => 200]),
        ]));

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $client->fetchDetailed('token', '2026-01-01', '2026-01-01');
    }
}
