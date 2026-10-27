<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Domain\BankAccess;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaApiClient;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTraceIdRecorder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AlfaApiClientTest extends TestCase
{
    private const ACCOUNT_NUMBER = '40702810900000000001';
    private const API_KEY = 'super-secret-api-key';
    private const BODY_SECRET = 'body-secret-value';

    private TestHandler $logs;
    private AlfaTraceIdRecorder $traceIds;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testSuccessfulResponseIsDecodedAndLoggedWithoutSensitiveData(): void
    {
        $client = $this->client(new MockResponse(
            '{"transactions":[{"note":"'.self::BODY_SECRET.'"}]}',
            ['http_code' => 200, 'response_headers' => ['X-TraceId: trace-42']],
        ));

        $payload = $client->getTransactions($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'), 1);

        self::assertSame([['note' => self::BODY_SECRET]], $payload['transactions']);
        $record = $this->singleRecord();
        self::assertSame('INFO', $record->level->getName());
        self::assertSame('Alfa API request', $record->message);
        self::assertSame('GET', $record->context['method']);
        self::assertSame('/api/jp/v1/statement/transactions', $record->context['path']);
        self::assertSame('trace-42', $record->context['trace_id']);
        self::assertSame(200, $record->context['status_code']);
        self::assertIsInt($record->context['duration_ms']);
        $this->assertLogsHaveNoSensitiveData();
    }

    /**
     * Регрессия: по спецификации Альфы выписка — под `/api`, как и список счетов; старый
     * клиент ходил на `/jp/v1/statement/transactions` без префикса.
     */
    public function testStatementUrlHasApiPrefixAndQuery(): void
    {
        $requested = [];
        $interactionIds = [];
        $client = $this->client(static function (string $method, string $url, array $options) use (&$requested, &$interactionIds): MockResponse {
            $requested[] = [$method, $url, $options['normalized_headers']['authorization'][0] ?? null];
            $interactionIds[] = substr((string) ($options['normalized_headers']['x-fapi-interaction-id'][0] ?? ''), \strlen('x-fapi-interaction-id: '));

            return new MockResponse('{"transactions":[]}', ['http_code' => 200]);
        });

        $client->getTransactions($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'), 2);
        $client->getAccounts($this->connection());

        self::assertSame([
            [
                'GET',
                'https://bank.test/api/jp/v1/statement/transactions?accountNumber='.self::ACCOUNT_NUMBER.'&statementDate=2026-10-01&page=2',
                'Authorization: Bearer '.self::API_KEY,
            ],
            ['GET', 'https://bank.test/api/jp/open-banking/v3.0/aisp-le/accounts', 'Authorization: Bearer '.self::API_KEY],
        ], $requested);
        foreach ($interactionIds as $interactionId) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $interactionId, 'x-fapi-interaction-id — UUID v4');
        }
        self::assertCount(2, array_unique($interactionIds), 'новый идентификатор на каждый запрос');
    }

    public function testSummaryUrlHasAccountAndDateWithoutLoggingThem(): void
    {
        $requested = [];
        $client = $this->client(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = [$method, $url];

            return new MockResponse('{"openingBalance":{"amount":1,"currencyName":"RUR"}}', ['http_code' => 200, 'response_headers' => ['X-TraceId' => 'trace-7']]);
        });

        $payload = $client->getSummary($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'));

        self::assertSame(['amount' => 1, 'currencyName' => 'RUR'], $payload['openingBalance']);
        self::assertSame([['GET', 'https://bank.test/api/jp/v1/statement/summary?accountNumber='.self::ACCOUNT_NUMBER.'&statementDate=2026-10-01']], $requested);
        self::assertSame('/api/jp/v1/statement/summary', $this->singleRecord()->context['path']);
        self::assertSame(['trace-7'], $this->traceIds->all(), 'X-TraceId — для отчёта проверки песочницы');
        $this->assertLogsHaveNoSensitiveData();
    }

    #[DataProvider('temporaryStatusProvider')]
    public function testTemporaryStatusThrowsUnavailable(int $status): void
    {
        $client = $this->client(new MockResponse('{"secret":"'.self::BODY_SECRET.'"}', ['http_code' => $status]));

        try {
            $client->getTransactions($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'), 1);
            self::fail('BankApiUnavailableException expected.');
        } catch (BankApiUnavailableException $exception) {
            self::assertSame($status, $exception->getStatusCode());
            self::assertSame('alfa', $exception->getBankCode());
            $this->assertExceptionChainHasNoSensitiveData($exception);
        }

        $record = $this->singleRecord();
        self::assertSame('WARNING', $record->level->getName());
        self::assertSame($status, $record->context['status_code']);
        $this->assertLogsHaveNoSensitiveData();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function temporaryStatusProvider(): iterable
    {
        yield 'request timeout' => [408];
        yield 'rate limited' => [429];
        yield 'server error' => [500];
        yield 'service unavailable' => [503];
    }

    #[DataProvider('permanentStatusProvider')]
    public function testPermanentStatusThrowsRequestFailed(int $status): void
    {
        $client = $this->client(new MockResponse('{"secret":"'.self::BODY_SECRET.'"}', ['http_code' => $status]));

        try {
            $client->getTransactions($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'), 1);
            self::fail('BankApiRequestFailedException expected.');
        } catch (BankApiRequestFailedException $exception) {
            self::assertSame($status, $exception->getStatusCode());
            $this->assertExceptionChainHasNoSensitiveData($exception);
        }

        self::assertSame('WARNING', $this->singleRecord()->level->getName());
        $this->assertLogsHaveNoSensitiveData();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function permanentStatusProvider(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }

    public function testTransportErrorThrowsUnavailableWithoutUrlInChain(): void
    {
        $client = $this->client(static function (string $method, string $url): MockResponse {
            throw new TransportException(sprintf('Connection refused for "%s".', $url));
        });

        try {
            $client->getTransactions($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'), 1);
            self::fail('BankApiUnavailableException expected.');
        } catch (BankApiUnavailableException $exception) {
            self::assertNull($exception->getStatusCode());
            $this->assertExceptionChainHasNoSensitiveData($exception);
        }

        $record = $this->singleRecord();
        self::assertSame('WARNING', $record->level->getName());
        self::assertSame(TransportException::class, $record->context['exception_class']);
        $this->assertLogsHaveNoSensitiveData();
    }

    public function testInterruptedBodyThrowsUnavailableWithoutUrlInChain(): void
    {
        $body = (static function (): \Generator {
            yield '{"transactions":[';

            throw new TransportException('Connection reset for "https://bank.test/jp/v1/statement/transactions?accountNumber='.self::ACCOUNT_NUMBER.'".');
        })();
        $client = $this->client(new MockResponse($body, ['http_code' => 200]));

        try {
            $client->getTransactions($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'), 1);
            self::fail('BankApiUnavailableException expected.');
        } catch (BankApiUnavailableException $exception) {
            self::assertNull($exception->getStatusCode());
            $this->assertExceptionChainHasNoSensitiveData($exception);
        }

        $record = $this->singleRecord();
        self::assertSame('WARNING', $record->level->getName());
        self::assertSame('Alfa API request failed: response body interrupted', $record->message);
        $this->assertLogsHaveNoSensitiveData();
    }

    public function testUnreadableBodyThrowsRequestFailed(): void
    {
        $client = $this->client(new MockResponse('not json '.self::BODY_SECRET, ['http_code' => 200]));

        try {
            $client->getTransactions($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'), 1);
            self::fail('BankApiRequestFailedException expected.');
        } catch (BankApiRequestFailedException $exception) {
            $this->assertExceptionChainHasNoSensitiveData($exception);
        }

        self::assertSame('WARNING', $this->singleRecord()->level->getName());
        $this->assertLogsHaveNoSensitiveData();
    }

    private function client(MockResponse|callable $response): AlfaApiClient
    {
        return new AlfaApiClient(new MockHttpClient($response), new Logger('test', [$this->logs]), $this->traceIds = new AlfaTraceIdRecorder());
    }

    private function connection(): BankAccess
    {
        return new BankAccess(Uuid::uuid7()->toString(), BankCode::ALFA, 'https://bank.test/', self::API_KEY);
    }

    private function singleRecord(): LogRecord
    {
        $records = $this->logs->getRecords();
        self::assertCount(1, $records);

        return $records[0];
    }

    private function assertLogsHaveNoSensitiveData(): void
    {
        foreach ($this->logs->getRecords() as $record) {
            $serialized = json_encode([$record->message, $record->context], \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::ACCOUNT_NUMBER, $serialized);
            self::assertStringNotContainsString(self::API_KEY, $serialized);
            self::assertStringNotContainsString(self::BODY_SECRET, $serialized);
        }
    }

    private function assertExceptionChainHasNoSensitiveData(\Throwable $exception): void
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::ACCOUNT_NUMBER, $current->getMessage());
            self::assertStringNotContainsString(self::BODY_SECRET, $current->getMessage());
        }
    }
}
