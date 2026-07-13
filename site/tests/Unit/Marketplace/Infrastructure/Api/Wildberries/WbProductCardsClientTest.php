<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Infrastructure\Api\Wildberries\WbProductCardsClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbProductCardsClientTest extends TestCase
{
    public function testFetchAllUsesWbCursorPagination(): void
    {
        $payloads = [];
        $page = 0;
        $firstPageCards = array_fill(0, 100, ['nmID' => 1]);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$page, &$payloads, $firstPageCards): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://content-api.wildberries.ru/content/v2/get/cards/list', $url);
            $payloads[] = $options['json'] ?? json_decode((string) ($options['body'] ?? 'null'), true);

            return 0 === $page++
                ? new MockResponse(json_encode([
                    'cards' => $firstPageCards,
                    'cursor' => ['updatedAt' => '2026-07-13T10:00:00Z', 'nmID' => 123, 'total' => 100],
                ], \JSON_THROW_ON_ERROR))
                : new MockResponse(json_encode([
                    'cards' => [['nmID' => 2]],
                    'cursor' => ['updatedAt' => '2026-07-13T10:01:00Z', 'nmID' => 124, 'total' => 1],
                ], \JSON_THROW_ON_ERROR));
        });

        $cards = $this->client($http)->fetchAll('token');

        self::assertCount(101, $cards);
        self::assertSame(100, $payloads[0]['settings']['cursor']['limit']);
        self::assertSame([
            'limit' => 100,
            'updatedAt' => '2026-07-13T10:00:00Z',
            'nmID' => 123,
        ], $payloads[1]['settings']['cursor']);
        self::assertSame(-1, $payloads[0]['settings']['filter']['withPhoto']);
    }

    public function testFullPageRequiresValidContinuationCursor(): void
    {
        $response = new MockResponse(json_encode([
            'cards' => array_fill(0, 100, ['nmID' => 1]),
            'cursor' => ['total' => 100],
        ], \JSON_THROW_ON_ERROR));

        $this->expectException(MarketplaceInvalidApiResponseException::class);

        $this->client(new MockHttpClient($response))->fetchAll('token');
    }

    public function testFetchAllRejectsCursorCycle(): void
    {
        $request = 0;
        $cursors = [
            ['updatedAt' => '2026-07-13T10:00:00Z', 'nmID' => 123],
            ['updatedAt' => '2026-07-13T10:01:00Z', 'nmID' => 124],
            ['updatedAt' => '2026-07-13T10:00:00Z', 'nmID' => 123],
        ];
        $http = new MockHttpClient(static function () use (&$request, $cursors): MockResponse {
            self::assertArrayHasKey($request, $cursors, 'Cursor cycle must be rejected before another request.');

            return new MockResponse(json_encode([
                'cards' => array_fill(0, 100, ['nmID' => 1]),
                'cursor' => $cursors[$request++],
            ], \JSON_THROW_ON_ERROR));
        });

        try {
            $this->client($http)->fetchAll('token');
            self::fail('Expected invalid API response exception.');
        } catch (MarketplaceInvalidApiResponseException $e) {
            self::assertSame('WB Product Cards cursor must advance.', $e->getMessage());
        }

        self::assertSame(3, $request);
    }

    public function testAuthErrorsAreClassified(): void
    {
        $this->expectException(MarketplaceAuthException::class);

        $this->client(new MockHttpClient(new MockResponse('{}', ['http_code' => 403])))->fetchAll('token');
    }

    public function testRateLimitIncludesRetryAfter(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{}', [
            'http_code' => 429,
            'response_headers' => ['Retry-After: 7'],
        ])));

        try {
            $client->fetchAll('token');
            self::fail('Expected rate limit exception.');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(7, $e->getRetryAfter());
        }
    }

    private function client(MockHttpClient $httpClient): WbProductCardsClient
    {
        return new WbProductCardsClient(
            $httpClient,
            new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage()),
        );
    }
}
