<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Api\Wildberries;

use App\Inventory\Exception\WbInventoryApiException;
use App\Inventory\Exception\WbInventoryRateLimitException;
use App\Inventory\Exception\WbInventoryTemporaryApiException;
use App\Inventory\Infrastructure\Api\Wildberries\WbInventoryClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WbInventoryClientTest extends TestCase
{
    public function testFetchesPageWithExpectedRequest(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://seller-analytics-api.wildberries.ru/api/analytics/v1/stocks-report/wb-warehouses', $url);
            self::assertContains('Authorization: test-token', $options['normalized_headers']['authorization']);
            self::assertSame([
                'nmIds' => [],
                'chrtIds' => [],
                'limit' => 2,
                'offset' => 4,
            ], json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR));

            return new MockResponse('{"data":{"items":[{"nmId":1},{"nmId":2}]}}', ['http_code' => 200]);
        });

        $response = (new WbInventoryClient($httpClient))->fetchStocks('test-token', 2, 4);

        self::assertCount(2, $response->items);
        self::assertTrue($response->hasNextPage);
    }

    public function testEmptyPageIsTerminal(): void
    {
        $client = new WbInventoryClient(new MockHttpClient(new MockResponse('{"data":{"items":[]}}', ['http_code' => 200])));

        $response = $client->fetchStocks('test-token', 10);

        self::assertSame([], $response->items);
        self::assertFalse($response->hasNextPage);
    }

    /** @dataProvider permanentErrorProvider */
    public function testPermanentHttpErrors(int $status): void
    {
        $client = new WbInventoryClient(new MockHttpClient(new MockResponse('{"error":"hidden"}', ['http_code' => $status])));

        $this->expectException(WbInventoryApiException::class);
        $this->expectExceptionMessage(sprintf('HTTP %d', $status));
        $client->fetchStocks('test-token');
    }

    /** @return iterable<string, array{int}> */
    public static function permanentErrorProvider(): iterable
    {
        foreach ([400, 401, 402, 403] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function testRateLimitCarriesRetryDelayWithoutResponseBody(): void
    {
        $client = new WbInventoryClient(new MockHttpClient(new MockResponse('{"secret":"must-not-leak"}', [
            'http_code' => 429,
            'response_headers' => ['retry-after: 27'],
        ])));

        try {
            $client->fetchStocks('test-token');
            self::fail('Expected rate limit exception.');
        } catch (WbInventoryRateLimitException $e) {
            self::assertSame(27, $e->retryAfterSeconds);
            self::assertStringNotContainsString('must-not-leak', $e->getMessage());
        }
    }

    public function testRateLimitDoesNotReadResponseBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('getStatusCode')->willReturn(429);
        $response->expects(self::once())->method('getHeaders')->with(false)->willReturn(['retry-after' => ['27']]);
        $response->expects(self::never())->method('getContent');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')->willReturn($response);

        $client = new WbInventoryClient($httpClient);

        $this->expectException(WbInventoryRateLimitException::class);
        $client->fetchStocks('test-token');
    }

    public function testServerErrorIsTemporary(): void
    {
        $client = new WbInventoryClient(new MockHttpClient(new MockResponse('', ['http_code' => 503])));

        $this->expectException(WbInventoryTemporaryApiException::class);
        $client->fetchStocks('test-token');
    }

    /** @dataProvider invalidPayloadProvider */
    public function testRejectsInvalidPayload(string $payload): void
    {
        $client = new WbInventoryClient(new MockHttpClient(new MockResponse($payload, ['http_code' => 200])));

        $this->expectException(WbInventoryApiException::class);
        $client->fetchStocks('test-token');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'invalid json' => ['{'];
        yield 'missing items' => ['{"data":{}}'];
        yield 'items not list' => ['{"data":{"items":{"key":"value"}}}'];
        yield 'non-object item' => ['{"data":{"items":[1]}}'];
    }
}
