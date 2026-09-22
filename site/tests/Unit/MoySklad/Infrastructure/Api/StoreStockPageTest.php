<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Infrastructure\Api;

use App\MoySklad\Exception\StockSyncException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

final class StoreStockPageTest extends TestCase
{
    public function testRequestsOrderedFilteredStorePage(): void
    {
        $body = '{"meta":{"type":"store","size":0,"limit":100,"offset":200},"rows":[]}';
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($body): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/api/remap/1.2/entity/store?limit=100&offset=200&order=id%2Casc&filter=archived%3Dtrue', $url);
            self::assertContains('Authorization: Bearer test-secret', $options['normalized_headers']['authorization']);
            self::assertContains('Accept: application/json;charset=utf-8', $options['normalized_headers']['accept']);
            self::assertArrayNotHasKey('accept-encoding', $options['normalized_headers']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse($body);
        });

        $page = (new MoySkladClient($http, 'https://example.test/api/remap/1.2'))->fetchStorePage('test-secret', true, 200);

        self::assertSame([], $page['rows']);
    }

    public function testReturnsUnmodifiedStockReportBody(): void
    {
        $body = '{"meta":{"type":"stockbystore","size":1,"limit":1000,"offset":0},"rows":[{"stock":12345678901234567890.1234567890}]}';
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($body): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/api/remap/1.2/report/stock/bystore?limit=1000&offset=0&groupBy=variant&filter=stockMode%3Dall', $url);
            self::assertContains('Authorization: Bearer test-secret', $options['normalized_headers']['authorization']);
            self::assertContains('Accept: application/json;charset=utf-8', $options['normalized_headers']['accept']);
            self::assertSame(30.0, $options['timeout']);
            self::assertSame(120.0, $options['max_duration']);

            return new MockResponse($body);
        });

        self::assertSame($body, (new MoySkladClient($http, 'https://example.test/api/remap/1.2'))->fetchStockReportPage('test-secret', 0));
    }

    #[DataProvider('httpFailures')]
    public function testClassifiesStockHttpFailures(int $status, string $category): void
    {
        $http = new MockHttpClient(new MockResponse('private-response private-object-name', ['http_code' => $status]));

        try {
            (new MoySkladClient($http, 'https://example.test'))->fetchStockReportPage('private-token', 0);
            self::fail('HTTP failure expected.');
        } catch (StockSyncException $error) {
            self::assertSame($category, $error->category);
            self::assertStringNotContainsString('private-', $error->getMessage());
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function httpFailures(): iterable
    {
        yield 'unauthorized' => [401, 'auth'];
        yield 'forbidden' => [403, 'forbidden'];
        yield 'rate limited' => [429, 'rate_limited'];
        yield 'server failure' => [503, 'temporary'];
        yield 'bad request' => [400, 'invalid_request'];
        yield 'redirect' => [302, 'invalid_request'];
    }

    public function testReadsBoundedRetryAfterMilliseconds(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => ['X-Lognex-Retry-After: 9999999']]));

        try {
            (new MoySkladClient($http, 'https://example.test'))->fetchStockReportPage('test-secret', 0);
            self::fail('Rate limit expected.');
        } catch (StockSyncException $error) {
            self::assertSame('rate_limited', $error->category);
            self::assertSame(3_600_000, $error->retryAfterMs);
        }
    }

    public function testTransportFailureIsTemporaryAndRetryableClientMakesOneRequest(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TransportException('private-token private-response private-object-name');
        });

        try {
            (new MoySkladClient(new RetryableHttpClient($http), 'https://example.test'))->fetchStockReportPage('private-token', 0);
            self::fail('Transport failure expected.');
        } catch (StockSyncException $error) {
            self::assertSame('temporary', $error->category);
            self::assertSame(1, $calls);
            self::assertStringNotContainsString('private-', $error->getMessage());
        }
    }

    public function testLogsOnlySafeStockRequestMetadata(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->with('info', 'MoySklad stock report page request', self::callback(static function (array $context): bool {
            self::assertSame(['method', 'url', 'httpStatus', 'durationMs'], array_keys($context));
            self::assertSame('https://example.test/api/remap/1.2/report/stock/bystore', $context['url']);
            self::assertStringNotContainsString('private-', serialize($context));

            return true;
        }));
        $http = new MockHttpClient(new MockResponse('private-response private-object-name'));

        self::assertSame('private-response private-object-name', (new MoySkladClient($http, 'https://example.test/api/remap/1.2', $logger))->fetchStockReportPage('private-token', 0));
    }
}
