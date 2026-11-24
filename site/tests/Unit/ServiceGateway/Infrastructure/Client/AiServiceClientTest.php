<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Exception\AiTaskRejectedException;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\AiServiceClient;
use App\ServiceGateway\Infrastructure\Client\AiTaskV1Parser;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiServiceClientTest extends TestCase
{
    private const TOKEN = 'symfony-to-ai-service-token-0123456789';

    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testSubmitsTaskWithIdempotencyKeyTokenAndTrace(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options];

            return new MockResponse(json_encode(AiTaskV1ParserTest::fixture('task_accepted'), \JSON_THROW_ON_ERROR), ['http_code' => 202]);
        });
        $request = $this->request();

        $accepted = $this->client($http)->submitTask($request);

        self::assertSame('0192f0a0-0000-7000-8000-000000000001', $accepted->taskId);
        [$method, $url, $options] = $seen;
        self::assertIsArray($options);
        self::assertSame('POST', $method);
        self::assertSame('http://ai-service:8080/internal/tasks', $url);
        self::assertIsArray($options['headers']);
        $headers = implode("\n", $options['headers']);
        self::assertStringContainsString('Authorization: Bearer '.self::TOKEN, $headers);
        self::assertStringContainsString('Idempotency-Key: key-1', $headers);
        self::assertStringContainsString('X-Trace-Id: '.$request['trace_id'], $headers);
        self::assertIsString($options['body']);
        self::assertSame($request, json_decode($options['body'], true));
        self::assertSame(3.0, $options['timeout']);
        self::assertSame(10.0, $options['max_duration']);
        $this->assertBodiesAndTokenNotLogged();
    }

    public function testUnauthorizedAndServerErrorsAreRetryable(): void
    {
        foreach ([401, 500, 503] as $status) {
            $http = new MockHttpClient(new MockResponse('{"error":{"code":"x","message":"secret"}}', ['http_code' => $status]));
            try {
                $this->client($http)->submitTask($this->request());
                self::fail('expected ServiceUnavailableException for '.$status);
            } catch (ServiceUnavailableException $e) {
                self::assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    public function testClientErrorsAreRejectionsWithStableCode(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"code":"idempotency_conflict","message":"Ключ уже использован"}}', ['http_code' => 409]));

        $this->expectException(AiTaskRejectedException::class);
        $this->expectExceptionMessage('HTTP 409 idempotency_conflict');
        $this->client($http)->submitTask($this->request());
    }

    public function testTransportErrorIsUnavailable(): void
    {
        $http = new MockHttpClient(static fn () => throw new TransportException('connection refused'));

        $this->expectException(ServiceUnavailableException::class);
        $this->client($http)->fetchResults(null, 100);
    }

    public function testNotConfiguredIsUnavailableWithoutRequest(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('no request expected'));
        $client = new AiServiceClient($http, new AiTaskV1Parser(), new Logger('test'), '', self::TOKEN);

        $this->expectException(ServiceUnavailableException::class);
        $client->fetchResults(null, 100);
    }

    public function testFetchesResultsWithCursorAndAcks(): void
    {
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $method.' '.$url;

            return str_contains($url, '/ack')
                ? new MockResponse('{"acked": 2}', ['http_code' => 200])
                : new MockResponse(json_encode(AiTaskV1ParserTest::fixture('results_page'), \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $client = $this->client($http);

        self::assertCount(2, $client->fetchResults('7', 50)->items);
        self::assertSame(2, $client->ack(['0192f0a0-0000-7000-8000-000000000001', '0192f0a0-0000-7000-8000-000000000002']));
        self::assertSame([
            'GET http://ai-service:8080/internal/tasks?status=done&limit=50&after=7',
            'POST http://ai-service:8080/internal/tasks/ack',
        ], $urls);
    }

    public function testInvalidAckResponseIsContractViolation(): void
    {
        $http = new MockHttpClient(new MockResponse('{"acked": "2"}', ['http_code' => 200]));

        $this->expectException(ContractViolationException::class);
        $this->client($http)->ack(['0192f0a0-0000-7000-8000-000000000001']);
    }

    private function client(MockHttpClient $http): AiServiceClient
    {
        return new AiServiceClient($http, new AiTaskV1Parser(), new Logger('test', [$this->logs]), 'http://ai-service:8080/', self::TOKEN);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(): array
    {
        return [
            'task_id' => '0192f0a0-0000-7000-8000-000000000001',
            'task_type' => 'echo',
            'contract_version' => 'v1',
            'company_id' => '0192f0a0-0000-7000-8000-0000000000c1',
            'idempotency_key' => 'key-1',
            'requested_by' => 'system',
            'created_at' => '2026-10-30T12:00:00+03:00',
            'trace_id' => '0192f0a0-0000-7000-8000-0000000000f1',
            'payload' => ['text' => 'секретный текст'],
        ];
    }

    private function assertBodiesAndTokenNotLogged(): void
    {
        foreach ($this->logs->getRecords() as $record) {
            $dump = json_encode([$record->message, $record->context], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(self::TOKEN, $dump);
            self::assertStringNotContainsString('секретный текст', $dump);
        }
    }
}
