<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\SourceFactsHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Фикстуры — копии `services/_contracts/source-fact-envelope/v1/examples`; совпадение копий и
 * соответствие выдачи сервиса примерам проверяет Go-тест `fetchkit/httpapi` (facts_test.go).
 */
final class SourceFactsHttpClientTest extends TestCase
{
    private const RESOURCE = 'ozon.finance.accrual-by-day';
    private const BATCH = '0195d3a0-1111-7000-8000-000000000001:ozon.finance.accrual-by-day:2026-11-11:r2';

    public static function example(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../Fixtures/ServiceGateway/source-fact-envelope-v1/'.$name.'.json');
    }

    public function testParsesBatchesExample(): void
    {
        $seen = [];
        $page = $this->client(self::example('batches_response'), 200, $seen)->fetchBatches(self::RESOURCE, 'cursor-1', 50);

        self::assertCount(1, $page->batches);
        $batch = $page->batches[0];
        self::assertSame(['ozon-fetcher', self::BATCH, '0195d3a0-1111-7000-8000-000000000001', null, self::RESOURCE, '2026-11-11', 2, 1],
            [$batch->service, $batch->batchId, $batch->connectionRef, $batch->companyId, $batch->resourceType, $batch->periodKey, $batch->revision, $batch->pageCount]);
        self::assertNull($page->nextCursor);
        self::assertStringStartsWith('http://ozon-fetcher:8080/internal/source-facts?', $seen['url']);
        self::assertStringContainsString('after=cursor-1', $seen['url']);
        self::assertStringContainsString('Authorization: Bearer token', implode("\n", $seen['headers']));
    }

    public function testPageExampleKeepsBodyUntouched(): void
    {
        $body = self::example('page_response');
        $page = $this->client($body)->fetchPage(self::BATCH, 1);

        self::assertSame(2, $page->recordCount);
        self::assertSame(1, $page->pageCount);
        self::assertSame($body, $page->body);
    }

    public function testAckExample(): void
    {
        self::assertSame(1, $this->client(self::example('ack_response'))->ack([self::BATCH]));
    }

    #[DataProvider('brokenPages')]
    public function testBrokenPageIsContractViolation(string $body, int $status = 200): void
    {
        $this->expectException(ContractViolationException::class);
        $this->client($body, $status)->fetchPage(self::BATCH, 1);
    }

    /**
     * @return iterable<string, array{0: string, 1?: int}>
     */
    public static function brokenPages(): iterable
    {
        $ok = self::example('page_response');
        yield 'unknown schema_version' => [str_replace('"schema_version":"1"', '"schema_version":"2"', $ok)];
        yield 'record_count differs' => [str_replace('"record_count":2', '"record_count":3', $ok)];
        yield 'other batch' => [str_replace(':r2"', ':r3"', $ok)];
        yield 'record without payload' => [str_replace('"payload":{', '"data":{', $ok)];
        yield 'not json' => ['<html>'];
        yield 'page lost in service' => ['{"error":{"code":"fact_page_invalid","message":"x"}}', 502];
        yield 'batch not found' => ['{"error":{"code":"batch_not_found","message":"x"}}', 404];
    }

    public function testServiceErrorsAreUnavailable(): void
    {
        foreach ([401, 500, 503] as $status) {
            try {
                $this->client('{"error":{"code":"x","message":"y"}}', $status)->fetchPage(self::BATCH, 1);
                self::fail('expected ServiceUnavailableException');
            } catch (ServiceUnavailableException $e) {
                self::assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    public function testNotConfiguredClientDoesNotCallService(): void
    {
        $client = new SourceFactsHttpClient(new MockHttpClient(static fn () => self::fail('no HTTP expected')), new NullLogger(), 'ozon-fetcher', '', null);

        self::assertFalse($client->isEnabled());
        $this->expectException(ServiceUnavailableException::class);
        $client->fetchBatches(self::RESOURCE, null, 10);
    }

    /**
     * @param array<string, mixed> $seen
     */
    private function client(string $body, int $status = 200, array &$seen = []): SourceFactsHttpClient
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($body, $status, &$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers']];

            return new MockResponse($body, ['http_code' => $status]);
        });

        return new SourceFactsHttpClient($http, new NullLogger(), 'ozon-fetcher', 'http://ozon-fetcher:8080', 'token');
    }
}
