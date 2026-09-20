<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Infrastructure\Api;

use App\MoySklad\Infrastructure\Api\MoySkladClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

final class CounterpartyPageTest extends TestCase
{
    public function testRequestsFilteredOrderedPageWithoutRedirectOrRetry(): void
    {
        $body = file_get_contents(__DIR__.'/../../../../Fixtures/MoySklad/Counterparty/counterparties_active_page_0.json');
        self::assertIsString($body);
        $calls = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, $body): MockResponse {
            ++$calls;
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/api/remap/1.2/entity/counterparty?limit=2&offset=0&order=id%2Casc&filter=archived%3Dfalse', $url);
            self::assertContains('Authorization: Bearer test-secret', $options['normalized_headers']['authorization']);
            self::assertContains('Accept: application/json;charset=utf-8', $options['normalized_headers']['accept']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse($body);
        });

        $page = (new MoySkladClient($http, 'https://example.test/api/remap/1.2'))->fetchCounterpartyPage('test-secret', false, 0, 2);
        self::assertCount(2, $page['rows']);
        self::assertSame(1, $calls);
    }

    #[DataProvider('errors')]
    public function testClassifiesHttpFailures(int $status, string $expected): void
    {
        $http = new MockHttpClient(new MockResponse('private-response', ['http_code' => $status]));
        try {
            (new MoySkladClient($http, 'https://example.test'))->fetchCounterpartyPage('private-token', false, 0);
            self::fail('Failure expected.');
        } catch (\App\MoySklad\Exception\CounterpartySyncException $e) {
            self::assertSame($expected, $e->category);
            self::assertStringNotContainsString('private-', $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function errors(): iterable
    {
        yield 'unauthorized' => [401, 'auth'];
        yield 'forbidden' => [403, 'forbidden'];
        yield 'rate limited' => [429, 'rate_limited'];
        yield 'server' => [503, 'temporary'];
        yield 'bad request' => [400, 'invalid_request'];
        yield 'redirect' => [302, 'invalid_request'];
    }

    public function testRejectsMismatchedPageCoordinates(): void
    {
        $http = new MockHttpClient(new MockResponse('{"meta":{"size":2,"limit":100,"offset":5},"rows":[]}'));
        $this->expectException(\App\MoySklad\Exception\CounterpartySyncException::class);
        (new MoySkladClient($http, 'https://example.test'))->fetchCounterpartyPage('test-secret', false, 0);
    }

    public function testReadsBoundedRateLimitDelay(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => ['X-Lognex-Retry-After: 1500']]));
        try {
            (new MoySkladClient($http, 'https://example.test'))->fetchCounterpartyPage('test-secret', false, 0);
            self::fail('Rate limit expected.');
        } catch (\App\MoySklad\Exception\CounterpartySyncException $e) {
            self::assertSame('rate_limited', $e->category);
            self::assertSame(1500, $e->retryAfterMs);
        }
    }

    #[DataProvider('malformedBodies')]
    public function testRejectsMalformedResponse(string $body): void
    {
        $http = new MockHttpClient(new MockResponse($body));
        try {
            (new MoySkladClient($http, 'https://example.test'))->fetchCounterpartyPage('test-secret', false, 0);
            self::fail('Malformed response expected.');
        } catch (\App\MoySklad\Exception\CounterpartySyncException $e) {
            self::assertSame('invalid_response', $e->category);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedBodies(): iterable
    {
        yield 'bad json' => ['{'];
        yield 'missing meta' => ['{"rows":[]}'];
        yield 'wrong rows' => ['{"meta":{"size":0,"limit":100,"offset":0},"rows":{}}'];
        yield 'wrong row' => ['{"meta":{"size":1,"limit":100,"offset":0},"rows":[null]}'];
    }

    public function testTransportFailureAndRetryableClientDoNotLeakOrRetry(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TransportException('private-token private-person');
        });
        try {
            (new MoySkladClient(new RetryableHttpClient($http), 'https://example.test'))->fetchCounterpartyPage('private-token', false, 0);
            self::fail('Transport failure expected.');
        } catch (\App\MoySklad\Exception\CounterpartySyncException $e) {
            self::assertSame('temporary', $e->category);
            self::assertStringNotContainsString('private-', $e->getMessage());
            self::assertSame(1, $calls);
        }
    }
}
