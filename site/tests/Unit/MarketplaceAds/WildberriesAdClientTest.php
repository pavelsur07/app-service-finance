<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Exception\WildberriesAdAuthException;
use App\MarketplaceAds\Exception\WildberriesAdRateLimitException;
use App\MarketplaceAds\Exception\WildberriesAdTransientException;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdClient;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesJsonDecoder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WildberriesAdClientTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const CONNECTION_ID = '22222222-2222-2222-2222-222222222222';

    private MarketplaceFacade&MockObject $marketplaceFacade;

    protected function setUp(): void
    {
        $this->marketplaceFacade = $this->createMock(MarketplaceFacade::class);
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(['api_key' => 'wb-secret-token', 'client_id' => null]);
    }

    public function testFetchExpensesUsesCurrentEndpointAndExactConnectionCredentials(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $http = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions): MockResponse {
                self::assertSame('GET', $method);
                $capturedUrl = $url;
                $capturedOptions = $options;

                return new MockResponse('[{"advertId":123,"updSum":10.0100}]', ['http_code' => 200]);
            },
        );
        $this->marketplaceFacade
            ->expects(self::once())
            ->method('getConnectionCredentials')
            ->with(
                self::COMPANY_ID,
                MarketplaceType::WILDBERRIES,
                MarketplaceConnectionType::SELLER,
                self::CONNECTION_ID,
            )
            ->willReturn(['api_key' => 'wb-secret-token', 'client_id' => null]);

        $rows = $this->client($http)->fetchExpenses(
            self::COMPANY_ID,
            self::CONNECTION_ID,
            new \DateTimeImmutable('2026-07-20T15:30:00+03:00'),
        );

        self::assertSame(
            'https://advert-api.wildberries.ru/adv/v1/upd?from=2026-07-20&to=2026-07-20',
            $capturedUrl,
        );
        self::assertSame(['Authorization: wb-secret-token'], $capturedOptions['normalized_headers']['authorization'] ?? null);
        self::assertSame('123', $rows[0]['advertId']);
        self::assertSame('10.0100', $rows[0]['updSum']);
    }

    public function testFetchFullStatisticsUsesGetAndRequiredQuery(): void
    {
        $capturedUrl = null;
        $http = new MockHttpClient(
            static function (string $method, string $url) use (&$capturedUrl): MockResponse {
                self::assertSame('GET', $method);
                $capturedUrl = $url;

                return new MockResponse('[{"advertId":2,"sum":1.25}]', ['http_code' => 200]);
            },
        );

        $rows = $this->client($http)->fetchFullStatistics(
            self::COMPANY_ID,
            self::CONNECTION_ID,
            new \DateTimeImmutable('2026-07-20'),
            ['2', '1', '2'],
        );

        self::assertSame(
            'https://advert-api.wildberries.ru/adv/v3/fullstats?ids=2%2C1&beginDate=2026-07-20&endDate=2026-07-20',
            $capturedUrl,
        );
        self::assertSame('1.25', $rows[0]['sum']);
    }

    public function testCombinedFetchUsesExpenseCampaignsChunksByFiftyAndSpacesRequests(): void
    {
        $expenseRows = [];
        for ($id = 51; $id >= 1; --$id) {
            $expenseRows[] = ['advertId' => $id, 'updSum' => 1];
        }

        $urls = [];
        $responses = [
            new MockResponse(json_encode($expenseRows, \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse('[]', ['http_code' => 200]),
            new MockResponse('[]', ['http_code' => 200]),
        ];
        $http = new MockHttpClient(
            static function (string $method, string $url) use (&$urls, &$responses): MockResponse {
                $urls[] = $url;

                return array_shift($responses) ?? throw new \LogicException('Unexpected request.');
            },
        );
        $clock = new MockClock('2026-07-21 00:00:00 UTC');

        $payload = $this->client($http, $clock)->fetchAdStatisticsForConnection(
            self::COMPANY_ID,
            self::CONNECTION_ID,
            new \DateTimeImmutable('2026-07-20'),
        );

        self::assertCount(3, $urls);
        $firstStatsQuery = [];
        parse_str((string) parse_url($urls[1], \PHP_URL_QUERY), $firstStatsQuery);
        self::assertCount(50, explode(',', $firstStatsQuery['ids']));
        self::assertStringContainsString('ids=51', $urls[2]);
        self::assertSame('2026-07-21 00:00:20', $clock->now()->format('Y-m-d H:i:s'));

        $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('wb-ad-daily-spend-v1', $decoded['schema']);
        self::assertCount(51, $decoded['expenses']);
        self::assertSame([], $decoded['statistics']);
        self::assertSame('1', $decoded['expenses'][0]['updSum']);
    }

    public function testEmptyExpensesDoNotRequestFullStatistics(): void
    {
        $http = new MockHttpClient([new MockResponse('[]', ['http_code' => 200])]);

        $payload = $this->client($http)->fetchAdStatisticsForConnection(
            self::COMPANY_ID,
            self::CONNECTION_ID,
            new \DateTimeImmutable('2026-07-20'),
        );

        self::assertSame(
            ['schema' => 'wb-ad-daily-spend-v1', 'expenses' => [], 'statistics' => []],
            json_decode($payload, true, 512, \JSON_THROW_ON_ERROR),
        );
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testRejectsInvalidFullStatsBatchBeforeHttpRequest(): void
    {
        $http = new MockHttpClient();

        $this->expectException(\InvalidArgumentException::class);

        $this->client($http)->fetchFullStatistics(
            self::COMPANY_ID,
            self::CONNECTION_ID,
            new \DateTimeImmutable('2026-07-20'),
            array_map('strval', range(1, 51)),
        );
    }

    public function testClassifiesAuthRateLimitServerAndInvalidJsonResponses(): void
    {
        $cases = [
            [new MockResponse('', ['http_code' => 403]), WildberriesAdAuthException::class],
            [new MockResponse('', ['http_code' => 500]), WildberriesAdTransientException::class],
            [new MockResponse('not-json', ['http_code' => 200]), WildberriesAdTransientException::class],
        ];

        foreach ($cases as [$response, $exceptionClass]) {
            try {
                $this->client(new MockHttpClient($response))->fetchExpenses(
                    self::COMPANY_ID,
                    self::CONNECTION_ID,
                    new \DateTimeImmutable('2026-07-20'),
                );
                self::fail('Expected WB API response to be classified.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf($exceptionClass, $exception);
            }
        }

        try {
            $this->client(new MockHttpClient(new MockResponse('', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => '37'],
            ])))->fetchExpenses(
                self::COMPANY_ID,
                self::CONNECTION_ID,
                new \DateTimeImmutable('2026-07-20'),
            );
            self::fail('Expected rate limit exception.');
        } catch (WildberriesAdRateLimitException $exception) {
            self::assertSame(37, $exception->retryAfterSeconds);
        }
    }

    public function testMissingCredentialsAreClassifiedWithoutHttpRequest(): void
    {
        $this->marketplaceFacade = $this->createMock(MarketplaceFacade::class);
        $this->marketplaceFacade
            ->method('getConnectionCredentials')
            ->willReturn(null);
        $http = new MockHttpClient();

        $this->expectException(WildberriesAdAuthException::class);

        $this->client($http)->fetchExpenses(
            self::COMPANY_ID,
            self::CONNECTION_ID,
            new \DateTimeImmutable('2026-07-20'),
        );
    }

    private function client(
        HttpClientInterface $httpClient,
        ?MockClock $clock = null,
    ): WildberriesAdClient {
        return new WildberriesAdClient(
            $httpClient,
            $this->marketplaceFacade,
            new WildberriesJsonDecoder(),
            $clock ?? new MockClock('2026-07-21 00:00:00 UTC'),
            new NullLogger(),
        );
    }
}
