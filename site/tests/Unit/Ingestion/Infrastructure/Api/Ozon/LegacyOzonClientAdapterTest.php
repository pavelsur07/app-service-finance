<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\CredentialNotFoundException;
use App\Ingestion\Infrastructure\Api\Ozon\LegacyOzonClientAdapter;
use App\Ingestion\Infrastructure\Api\Ozon\OzonCredentialProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LegacyOzonClientAdapterTest extends TestCase
{
    public function testFetchTransactionListMapsSuccessfulResponse(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions): MockResponse {
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse(
                '{"result":{"operations":[{"operation_id":"1"}],"page_count":2}}',
                ['http_code' => 200],
            );
        });

        $adapter = new LegacyOzonClientAdapter($http, $this->credentialProvider(), new NullLogger());
        $page = $adapter->fetchTransactionList(
            '0192f0c2-0000-7000-8000-000000000001',
            'marketplace:ozon:seller',
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-07'),
            1,
            1000,
        );

        self::assertSame('https://api-seller.ozon.ru/v3/finance/transaction/list', $capturedUrl);
        $requestPayload = $capturedOptions['json'] ?? json_decode((string) ($capturedOptions['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('all', $requestPayload['filter']['transaction_type']);
        self::assertSame([['operation_id' => '1']], $page->rows);
        self::assertTrue($page->hasMore);
        self::assertSame('2', $page->nextPageToken);
    }

    public function testFetchRealizationMapsRowsAndHeaderMetadata(): void
    {
        $capturedUrl = null;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse(
                '{"result":{"rows":[{"operation_id":"real-1"}],"header":{"doc_number":"doc-1"},"header_additional":{"currency_code":"RUB"}}}',
                ['http_code' => 200],
            );
        });

        $adapter = new LegacyOzonClientAdapter($http, $this->credentialProvider(), new NullLogger());
        $page = $adapter->fetchRealization(
            '0192f0c2-0000-7000-8000-000000000001',
            'marketplace:ozon:seller',
            2026,
            2,
        );

        self::assertSame('https://api-seller.ozon.ru/v2/finance/realization', $capturedUrl);
        self::assertSame([['operation_id' => 'real-1']], $page->rows);
        self::assertFalse($page->hasMore);
        self::assertSame(['doc_number' => 'doc-1'], $page->metadata['header']);
        self::assertSame(['currency_code' => 'RUB'], $page->metadata['headerAdditional']);
    }

    public function testMissingCredentialsBecomeAuthException(): void
    {
        $provider = new class implements OzonCredentialProviderInterface {
            public function read(string $companyId, string $connectionRef): array
            {
                throw new CredentialNotFoundException('missing');
            }
        };

        $adapter = new LegacyOzonClientAdapter(new MockHttpClient(), $provider, new NullLogger());

        $this->expectException(ConnectorAuthException::class);
        $adapter->listClusters('0192f0c2-0000-7000-8000-000000000001', 'marketplace:ozon:seller');
    }

    public function testUnauthorizedStatusBecomesAuthException(): void
    {
        $adapter = new LegacyOzonClientAdapter(
            new MockHttpClient(new MockResponse('{"error":"forbidden"}', ['http_code' => 403])),
            $this->credentialProvider(),
            new NullLogger(),
        );

        $this->expectException(ConnectorAuthException::class);
        $adapter->fetchTransactionList(
            '0192f0c2-0000-7000-8000-000000000001',
            'marketplace:ozon:seller',
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-07'),
            1,
            1000,
        );
    }

    public function testRateLimitStatusBecomesTransientException(): void
    {
        $adapter = new LegacyOzonClientAdapter(
            new MockHttpClient(new MockResponse('{"error":"rate"}', ['http_code' => 429])),
            $this->credentialProvider(),
            new NullLogger(),
        );

        $this->expectException(ConnectorTransientException::class);
        $adapter->fetchTransactionList(
            '0192f0c2-0000-7000-8000-000000000001',
            'marketplace:ozon:seller',
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-07'),
            1,
            1000,
        );
    }

    public function testServerErrorBecomesTransientExceptionWithoutLoggingResponseBody(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => $message,
                    'context' => $context,
                ];
            }
        };
        $adapter = new LegacyOzonClientAdapter(
            new MockHttpClient(new MockResponse('{"secret":"response-body"}', ['http_code' => 500])),
            $this->credentialProvider(),
            $logger,
        );

        try {
            $adapter->fetchTransactionList(
                '0192f0c2-0000-7000-8000-000000000001',
                'marketplace:ozon:seller',
                new \DateTimeImmutable('2026-02-01'),
                new \DateTimeImmutable('2026-02-07'),
                1,
                1000,
            );
            self::fail('Expected transient Ozon exception.');
        } catch (ConnectorTransientException) {
        }

        self::assertNotSame([], $logger->records);
        foreach ($logger->records as $record) {
            self::assertStringNotContainsString('response-body', json_encode($record, \JSON_THROW_ON_ERROR));
            self::assertArrayNotHasKey('body', $record['context']);
            self::assertArrayNotHasKey('response', $record['context']);
        }
    }

    public function testTransportFailureBecomesTransientException(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('timeout');
        });
        $adapter = new LegacyOzonClientAdapter($http, $this->credentialProvider(), new NullLogger());

        $this->expectException(ConnectorTransientException::class);
        $adapter->fetchTransactionList(
            '0192f0c2-0000-7000-8000-000000000001',
            'marketplace:ozon:seller',
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-07'),
            1,
            1000,
        );
    }

    public function testInvalidJsonFailsAsRuntimeException(): void
    {
        $adapter = new LegacyOzonClientAdapter(
            new MockHttpClient(new MockResponse('not-json', ['http_code' => 200])),
            $this->credentialProvider(),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');
        $adapter->fetchTransactionList(
            '0192f0c2-0000-7000-8000-000000000001',
            'marketplace:ozon:seller',
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-07'),
            1,
            1000,
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
