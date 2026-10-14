<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\Bank\Provider\Alfa;

use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Exception\BankApiRequestFailedException;
use App\Cash\Exception\BankApiUnavailableException;
use App\Cash\Service\Import\Bank\Provider\Alfa\AlfaApiClient;
use App\Company\Entity\Company;
use App\Company\Entity\User;
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

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testSuccessfulResponseIsDecodedAndLoggedWithoutSensitiveData(): void
    {
        $client = $this->client(new MockResponse('{"transactions":[{"note":"'.self::BODY_SECRET.'"}]}', ['http_code' => 200]));

        $payload = $client->getTransactions($this->connection(), self::ACCOUNT_NUMBER, new \DateTimeImmutable('2026-10-01'), 1);

        self::assertSame([['note' => self::BODY_SECRET]], $payload['transactions']);
        $record = $this->singleRecord();
        self::assertSame('INFO', $record->level->getName());
        self::assertSame('Alfa API request', $record->message);
        self::assertSame('GET', $record->context['method']);
        self::assertSame('/jp/v1/statement/transactions', $record->context['path']);
        self::assertSame(200, $record->context['status_code']);
        self::assertIsInt($record->context['duration_ms']);
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
        return new AlfaApiClient(new MockHttpClient($response), new Logger('test', [$this->logs]));
    }

    private function connection(): BankConnection
    {
        $company = new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));

        return new BankConnection(Uuid::uuid4()->toString(), $company, 'alfa', self::API_KEY, 'https://bank.test');
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
