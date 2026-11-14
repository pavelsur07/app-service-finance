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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiEvalClientTest extends TestCase
{
    private const RUN = '0192f0a0-0000-7000-8000-000000000001';
    private const TRACE = '0192f0a0-0000-7000-8000-0000000000f1';
    private const TOKEN = 'synthetic-eval-secret';

    public function testSubmitsExactWireWithIdempotencyTraceAndShortTimeouts(): void
    {
        $logs = new TestHandler();
        $seen = [];
        $http = new MockHttpClient(static function ($method, $url, $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options];

            return new MockResponse(json_encode(AiEvalV1ParserTest::fixture('accepted'), \JSON_THROW_ON_ERROR), ['http_code' => 202]);
        });
        $client = $this->client($http, $logs);
        $accepted = $client->submitEvalRun(self::RUN, '2026-11-14', self::TRACE, 'payment_intake:2026-11-14');
        self::assertSame(self::RUN, $accepted->runId);
        [$method, $url, $options] = $seen;
        self::assertSame('POST', $method);
        self::assertSame('http://ai-service:8080/internal/eval-runs', $url);
        self::assertSame([
            'contract_version' => 'v1', 'run_id' => self::RUN, 'suite' => 'payment_intake',
            'run_date' => '2026-11-14', 'trace_id' => self::TRACE, 'idempotency_key' => 'payment_intake:2026-11-14',
        ], json_decode($options['body'], true, 64, \JSON_THROW_ON_ERROR));
        $headers = implode("\n", $options['headers']);
        self::assertStringContainsString('Authorization: Bearer '.self::TOKEN, $headers);
        self::assertStringContainsString('Idempotency-Key: payment_intake:2026-11-14', $headers);
        self::assertStringContainsString('X-Trace-Id: '.self::TRACE, $headers);
        self::assertSame(3.0, $options['timeout']);
        self::assertSame(10.0, $options['max_duration']);
        $this->assertSafeLogs($logs);
    }

    public function testFetchesExactRunAndLatestWithoutChangingExistingInterface(): void
    {
        $urls = [];
        $http = new MockHttpClient(static function ($method, $url, $options) use (&$urls): MockResponse {
            $urls[] = $method.' '.$url;
            self::assertSame(3.0, $options['timeout']);
            self::assertSame(10.0, $options['max_duration']);
            self::assertMatchesRegularExpression('/X-Trace-Id: [0-9a-f-]{36}/', implode("\n", $options['headers']));

            return new MockResponse(json_encode(AiEvalV1ParserTest::fixture(str_contains($url, '/latest') ? 'latest' : 'run'), \JSON_THROW_ON_ERROR));
        });
        $logs = new TestHandler();
        $client = $this->client($http, $logs);
        self::assertTrue($client->fetchEvalRun(self::RUN)->isPassing());
        $latest = $client->fetchLatestEvalRun();
        self::assertNotNull($latest->run);
        self::assertTrue($latest->run->isPassing());
        self::assertSame([
            'GET http://ai-service:8080/internal/eval-runs/'.self::RUN,
            'GET http://ai-service:8080/internal/eval-runs/latest?suite=payment_intake',
        ], $urls);
        $this->assertSafeLogs($logs);
    }

    #[DataProvider('retryableStatuses')]
    public function testReusesRetryableErrorHandlingWithoutLoggingBody(int $status): void
    {
        $logs = new TestHandler();
        $client = $this->client(new MockHttpClient(new MockResponse('{"error":{"code":"unavailable","message":"RAW_FINANCE_SENTINEL"}}', ['http_code' => $status])), $logs);
        try {
            $client->fetchLatestEvalRun();
            self::fail('Retryable status accepted');
        } catch (ServiceUnavailableException) {
            $this->assertSafeLogs($logs);
        }
    }

    /** @return iterable<array{int}> */
    public static function retryableStatuses(): iterable
    {
        foreach ([401, 408, 429, 500, 503] as $status) {
            yield [$status];
        }
    }

    public function testConflictRetainsStableCodeAndCanonicalDuplicateRun(): void
    {
        $wire = AiEvalV1ParserTest::fixture('accepted');
        $wire->run_id = '0192f0a0-0000-7000-8000-000000000002';
        $wire->duplicate = true;
        $http = new MockHttpClient([
            new MockResponse(json_encode($wire, \JSON_THROW_ON_ERROR), ['http_code' => 202]),
            new MockResponse('{"error":{"code":"eval_idempotency_conflict","message":"RAW_FINANCE_SENTINEL"}}', ['http_code' => 409]),
        ]);
        $client = $this->client($http, new TestHandler());
        $accepted = $client->submitEvalRun(self::RUN, '2026-11-14', self::TRACE, 'payment_intake:2026-11-14');
        self::assertTrue($accepted->duplicate);
        self::assertSame('0192f0a0-0000-7000-8000-000000000002', $accepted->runId);
        $this->expectException(AiTaskRejectedException::class);
        $this->expectExceptionMessage('eval_idempotency_conflict');
        $client->submitEvalRun(self::RUN, '2026-11-14', self::TRACE, 'payment_intake:2026-11-14');
    }

    public function testMalformedJsonUsesEvalContractAndSafeLogs(): void
    {
        $logs = new TestHandler();
        try {
            $this->client(new MockHttpClient(new MockResponse('RAW_MODEL_SENTINEL')), $logs)->fetchLatestEvalRun();
            self::fail('Invalid JSON accepted');
        } catch (ContractViolationException $e) {
            self::assertStringContainsString('ai-eval-run/v1', $e->getMessage());
            self::assertStringNotContainsString('RAW_', $e->getMessage());
            $this->assertSafeLogs($logs);
        }
    }

    public function testTransportAndMissingConfigurationNeverBecomeSuccess(): void
    {
        $http = new MockHttpClient(static fn () => throw new TransportException('synthetic failure'));
        try {
            $this->client($http, new TestHandler())->fetchEvalRun(self::RUN);
            self::fail('Transport failure accepted');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('ai-service', $e->getMessage());
        }
        $client = new AiServiceClient(new MockHttpClient(static fn () => self::fail('No request expected')), new AiTaskV1Parser(), new Logger('test'), '', self::TOKEN);
        $this->expectException(ServiceUnavailableException::class);
        $client->fetchLatestEvalRun();
    }

    private function client(MockHttpClient $http, TestHandler $logs): AiServiceClient
    {
        return new AiServiceClient($http, new AiTaskV1Parser(), new Logger('test', [$logs]), 'http://ai-service:8080/', self::TOKEN);
    }

    private function assertSafeLogs(TestHandler $logs): void
    {
        self::assertNotEmpty($logs->getRecords());
        foreach ($logs->getRecords() as $record) {
            $dump = json_encode([$record->message, $record->context], \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TOKEN, $dump);
            self::assertStringNotContainsString('RAW_', $dump);
            self::assertStringNotContainsString('configuration_fingerprint', $dump);
        }
    }
}
