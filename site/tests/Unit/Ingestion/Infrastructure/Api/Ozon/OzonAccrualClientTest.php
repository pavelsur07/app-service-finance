<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonAccrualClient;
use App\Ingestion\Infrastructure\Api\Ozon\OzonCredentialProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonAccrualClientTest extends TestCase
{
    public function testFetchPostingsSendsExpectedRequestAndExtractsRows(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions): MockResponse {
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse(
                '{"result":{"postings":[{"posting_number":"posting-1"}]}}',
                ['http_code' => 200],
            );
        });

        $client = new OzonAccrualClient($http, $this->credentialProvider(), new NullLogger());
        $page = $client->fetchPostings(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
            ['posting-1', ' posting-2 ', 'posting-1'],
        );

        self::assertSame('https://api-seller.ozon.ru/v1/finance/accrual/postings', $capturedUrl);
        $requestPayload = $capturedOptions['json'] ?? json_decode((string) ($capturedOptions['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['posting-1', 'posting-2'], $requestPayload['posting_numbers']);
        self::assertSame(['Client-Id: client-id'], $capturedOptions['normalized_headers']['client-id'] ?? null);
        self::assertSame(['Api-Key: api-key'], $capturedOptions['normalized_headers']['api-key'] ?? null);
        self::assertSame([['posting_number' => 'posting-1']], $page->rows);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextPageToken);
    }

    public function testFetchByDayExtractsResultListRows(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions): MockResponse {
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse(
                '{"accruals":[{"date":"2026-06-13","accrual_id":1}],"last_id":""}',
                ['http_code' => 200],
            );
        });

        $client = new OzonAccrualClient(
            $http,
            $this->credentialProvider(),
            new NullLogger(),
        );

        $page = $client->fetchByDay(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
            new \DateTimeImmutable('2026-06-13'),
        );

        self::assertSame('https://api-seller.ozon.ru/v1/finance/accrual/by-day', $capturedUrl);
        $requestPayload = $capturedOptions['json'] ?? json_decode((string) ($capturedOptions['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-06-13', $requestPayload['date']);
        self::assertSame([['date' => '2026-06-13', 'accrual_id' => 1]], $page->rows);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextPageToken);
    }

    public function testFetchByDaySendsLastIdAndReturnsNextLastId(): void
    {
        $capturedOptions = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse(
                '{"result":{"accruals":[{"date":"2026-06-13","accrual_id":2}],"last_id":"next-last-id"}}',
                ['http_code' => 200],
            );
        });

        $client = new OzonAccrualClient(
            $http,
            $this->credentialProvider(),
            new NullLogger(),
        );

        $page = $client->fetchByDay(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
            new \DateTimeImmutable('2026-06-13'),
            'previous-last-id',
        );

        $requestPayload = $capturedOptions['json'] ?? json_decode((string) ($capturedOptions['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('2026-06-13', $requestPayload['date']);
        self::assertSame('previous-last-id', $requestPayload['last_id']);
        self::assertSame([['date' => '2026-06-13', 'accrual_id' => 2]], $page->rows);
        self::assertTrue($page->hasMore);
        self::assertSame('next-last-id', $page->nextPageToken);
    }

    public function testFetchByDayIgnoresHasNextWhenLastIdIsEmpty(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"result":{"accruals":[{"date":"2026-06-13","accrual_id":3}],"last_id":"","has_next":true}}',
            ['http_code' => 200],
        ));

        $client = new OzonAccrualClient(
            $http,
            $this->credentialProvider(),
            new NullLogger(),
        );

        $page = $client->fetchByDay(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
            new \DateTimeImmutable('2026-06-13'),
            'previous-last-id',
        );

        self::assertSame([['date' => '2026-06-13', 'accrual_id' => 3]], $page->rows);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextPageToken);
    }

    public function testFetchTypesSendsEmptyJsonObjectAndExtractsAccrualTypes(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions): MockResponse {
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse(
                '{"result":{"accrual_types":[{"id":1,"name":"Эквайринг"}]}}',
                ['http_code' => 200],
            );
        });

        $client = new OzonAccrualClient(
            $http,
            $this->credentialProvider(),
            new NullLogger(),
        );

        $page = $client->fetchTypes(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
        );

        self::assertSame('https://api-seller.ozon.ru/v1/finance/accrual/types', $capturedUrl);
        self::assertSame('{}', (string) ($capturedOptions['body'] ?? ''));
        self::assertSame([['id' => 1, 'name' => 'Эквайринг']], $page->rows);
        self::assertFalse($page->hasMore);
    }

    public function testFetchPostingsRequiresPostingNumbers(): void
    {
        $client = new OzonAccrualClient(
            new MockHttpClient(new MockResponse('{}', ['http_code' => 200])),
            $this->credentialProvider(),
            new NullLogger(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('posting number');

        $client->fetchPostings(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
            ['', '  '],
        );
    }

    public function testUnauthorizedStatusBecomesAuthException(): void
    {
        $client = new OzonAccrualClient(
            new MockHttpClient(new MockResponse('{"error":"forbidden"}', ['http_code' => 403])),
            $this->credentialProvider(),
            new NullLogger(),
        );

        $this->expectException(ConnectorAuthException::class);
        $client->fetchTypes(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
        );
    }

    public function testCredentialBadRequestStatusBecomesAuthException(): void
    {
        $client = new OzonAccrualClient(
            new MockHttpClient(new MockResponse('{"code":3,"message":"Client-Id is invalid"}', ['http_code' => 400])),
            $this->credentialProvider(),
            new NullLogger(),
        );

        $this->expectException(ConnectorAuthException::class);
        $client->fetchTypes(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
        );
    }

    public function testGenericBadRequestStatusStaysRuntimeException(): void
    {
        $client = new OzonAccrualClient(
            new MockHttpClient(new MockResponse(
                '{"code":3,"message":"Request validation error: invalid GetFinanceAccrualPostingsRequest.PostingNumbers: value must contain between 1 and 200 items, inclusive"}',
                ['http_code' => 400],
            )),
            $this->credentialProvider(),
            new NullLogger(),
        );

        try {
            $client->fetchTypes(
                '0192f0c2-0000-7000-8000-000000000001',
                '0192f0c2-0000-7000-8000-000000000002',
            );
            self::fail('Expected generic runtime exception.');
        } catch (ConnectorAuthException) {
            self::fail('Generic bad request must not be classified as auth.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('HTTP 400', $exception->getMessage());
        }
    }

    public function testRateLimitStatusBecomesTransientException(): void
    {
        $client = new OzonAccrualClient(
            new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429])),
            $this->credentialProvider(),
            new NullLogger(),
        );

        $this->expectException(ConnectorTransientException::class);
        $client->fetchTypes(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
        );
    }

    private function credentialProvider(): OzonCredentialProviderInterface
    {
        return new class implements OzonCredentialProviderInterface {
            public function read(string $companyId, string $connectionRef): array
            {
                return ['api_key' => 'api-key', 'client_id' => 'client-id'];
            }
        };
    }
}
