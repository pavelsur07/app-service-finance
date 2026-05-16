<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Api\Ozon;

use App\Inventory\Exception\OzonInventoryApiException;
use App\Inventory\Exception\OzonInventoryRateLimitException;
use App\Inventory\Infrastructure\Api\Ozon\OzonInventoryClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonInventoryClientTest extends TestCase
{
    /**
     * Contract for Ozon Seller API POST /v4/product/info/stocks:
     * request uses `filter.visibility=ALL` + `limit` + optional `last_id`, response cursor is `result.last_id`, items are in `result.items`.
     */
    public function testSuccessReturnsRawResponseWithoutNormalizationForV4ProductInfoStocksContract(): void
    {
        $payload = [
            'result' => [
                'items' => [['product_id' => 111, 'offer_id' => 'sku-1', 'stocks' => ['present' => 7]]],
                'last_id' => 'next-page-id',
            ],
        ];

        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), ['http_code' => 200])));

        $response = $client->fetchStocks('cid', 'key', 500, 'prev-page-id');

        self::assertSame($payload, $response->raw);
        self::assertSame('next-page-id', $response->nextLastId);
    }

    public function testBadRequest400MapsToApiException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{"message":"bad request"}', ['http_code' => 400])));

        $this->expectException(OzonInventoryApiException::class);
        $client->fetchStocks('cid', 'key');
    }

    public function testRateLimit429MapsToRateLimitException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{"message":"rate"}', ['http_code' => 429])));

        $this->expectException(OzonInventoryRateLimitException::class);
        $client->fetchStocks('cid', 'key');
    }

    public function testForbidden403MapsToPermanentException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{"message":"forbidden"}', ['http_code' => 403])));

        $this->expectException(OzonInventoryApiException::class);
        $client->fetchStocks('cid', 'key');
    }

    public function testUnauthorized401MapsToApiException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{"message":"unauthorized"}', ['http_code' => 401])));

        $this->expectException(OzonInventoryApiException::class);
        $client->fetchStocks('cid', 'key');
    }

    public function testServerError5xxThrowsRetryableRuntimeException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{"message":"server"}', ['http_code' => 500])));

        $this->expectException(\RuntimeException::class);
        $client->fetchStocks('cid', 'key');
    }

    public function testInvalidJsonThrowsException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{invalid', ['http_code' => 200])));

        $this->expectException(OzonInventoryApiException::class);
        $client->fetchStocks('cid', 'key');
    }

    public function testEmptyClientIdThrowsValidationException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{"result":{"items":[]}}', ['http_code' => 200])));

        $this->expectException(\InvalidArgumentException::class);
        $client->fetchStocks('', 'key');
    }

    public function testEmptyApiKeyThrowsValidationException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{"result":{"items":[]}}', ['http_code' => 200])));

        $this->expectException(\InvalidArgumentException::class);
        $client->fetchStocks('cid', '');
    }

    public function testInvalidLimitThrowsValidationException(): void
    {
        $client = new OzonInventoryClient(new MockHttpClient(new MockResponse('{"result":{"items":[]}}', ['http_code' => 200])));

        $this->expectException(\InvalidArgumentException::class);
        $client->fetchStocks('cid', 'key', 0);
    }



    public function testRequestBodyDoesNotContainLastIdWhenCursorIsNull(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options = []) use (&$captured): MockResponse {
            $captured = $options['json'] ?? json_decode((string) ($options['body'] ?? 'null'), true);

            return new MockResponse('{"result":{"items":[]}}', ['http_code' => 200]);
        });

        $client = new OzonInventoryClient($http);
        $client->fetchStocks('client-1', 'api-1', 100, null);

        self::assertSame(['filter' => ['visibility' => 'ALL'], 'limit' => 100], $captured);
        self::assertArrayNotHasKey('last_id', $captured);
    }

    public function testCredentialsAndRequestBodyArePassedCorrectly(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options = []) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"result":{"items":[],"last_id":""}}', ['http_code' => 200]);
        });

        $client = new OzonInventoryClient($http);
        $client->fetchStocks('client-1', 'api-1', 321, 'last-42');

        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/v4/product/info/stocks', $captured['url']);
        $headers = $captured['options']['headers'] ?? [];
        $norm = $captured['options']['normalized_headers'] ?? [];
        $clientId = $headers['Client-Id'] ?? $headers['client-id'] ?? ($norm['client-id'][0] ?? null);
        $apiKey = $headers['Api-Key'] ?? $headers['api-key'] ?? ($norm['api-key'][0] ?? null);
        self::assertSame('client-1', $clientId);
        self::assertSame('api-1', $apiKey);
        $payload = $captured['options']['json'] ?? json_decode((string) ($captured['options']['body'] ?? 'null'), true);
        self::assertSame(['visibility' => 'ALL'], $payload['filter']);
        self::assertSame(321, $payload['limit']);
        self::assertSame('last-42', $payload['last_id']);
    }
}
