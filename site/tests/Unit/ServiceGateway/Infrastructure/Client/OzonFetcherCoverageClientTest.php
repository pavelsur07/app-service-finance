<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\OzonFetcherCoverageClient;
use App\ServiceGateway\Infrastructure\Client\ServiceCoverageV1Parser;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonFetcherCoverageClientTest extends TestCase
{
    private const TOKEN = 'symfony-to-service-token-0123456789abcdef';

    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testFetchesCoverageWithTokenTraceIdAndTimeouts(): void
    {
        $seenMethod = $seenUrl = '';
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenMethod, &$seenUrl, &$seenOptions): MockResponse {
            [$seenMethod, $seenUrl, $seenOptions] = [$method, $url, $options];

            return new MockResponse(json_encode(ServiceCoverageV1ParserTest::fixture(), \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $report = $this->client($http)->fetchCoverage();

        self::assertCount(3, $report->rows);
        self::assertSame('GET', $seenMethod);
        self::assertSame('http://ozon-fetcher:8080/internal/coverage', $seenUrl);
        self::assertIsArray($seenOptions['headers']);
        $headers = implode("\n", $seenOptions['headers']);
        self::assertStringContainsString('Authorization: Bearer '.self::TOKEN, $headers);
        self::assertMatchesRegularExpression('/X-Trace-Id: [0-9a-f-]{36}/', $headers);
        self::assertSame(2.0, $seenOptions['timeout']);
        self::assertSame(5.0, $seenOptions['max_duration']);
        $this->assertTokenNotLogged();
        self::assertTrue($this->logs->hasInfoThatContains('Service coverage fetched.'));
        self::assertSame(200, $this->logs->getRecords()[0]->context['status']);
    }

    public function testNonOkStatusIsUnavailable(): void
    {
        foreach ([401, 500, 503] as $status) {
            $http = new MockHttpClient(new MockResponse('{"error":{"code":"x","message":"y"}}', ['http_code' => $status]));
            try {
                $this->client($http)->fetchCoverage();
                self::fail('expected ServiceUnavailableException for '.$status);
            } catch (ServiceUnavailableException $e) {
                self::assertStringContainsString((string) $status, $e->getMessage());
            }
        }
        $this->assertTokenNotLogged();
        self::assertTrue($this->logs->hasWarningThatContains('Service coverage request failed.'));
    }

    public function testTransportErrorIsUnavailable(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Connection refused for Bearer '.self::TOKEN));

        $this->expectException(ServiceUnavailableException::class);
        try {
            $this->client($http)->fetchCoverage();
        } finally {
            $this->assertTokenNotLogged();
        }
    }

    public function testTimeoutIsUnavailable(): void
    {
        $http = new MockHttpClient(new MockResponse([''], ['http_code' => 200, 'error' => 'Idle timeout reached']));

        $this->expectException(ServiceUnavailableException::class);
        $this->client($http)->fetchCoverage();
    }

    public function testInvalidJsonIsContractViolation(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>gateway</html>', ['http_code' => 200]));

        $this->expectException(ContractViolationException::class);
        $this->client($http)->fetchCoverage();
    }

    public function testRowsAsJsonObjectIsContractViolation(): void
    {
        $body = json_encode(['rows' => []] + ServiceCoverageV1ParserTest::fixture(), \JSON_THROW_ON_ERROR);
        $http = new MockHttpClient(new MockResponse(str_replace('"rows":[]', '"rows":{}', $body), ['http_code' => 200]));

        $this->expectException(ContractViolationException::class);
        $this->expectExceptionMessage('$.rows');
        $this->client($http)->fetchCoverage();
    }

    public function testForeignServiceNameIsContractViolation(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['service' => 'wb-fetcher'] + ServiceCoverageV1ParserTest::fixture(), \JSON_THROW_ON_ERROR), ['http_code' => 200]));

        $this->expectException(ContractViolationException::class);
        $this->expectExceptionMessage('$.service');
        $this->client($http)->fetchCoverage();
    }

    public function testDisabledWithoutUrlOrToken(): void
    {
        $http = new MockHttpClient();
        self::assertTrue($this->client($http)->isEnabled());
        self::assertFalse($this->client($http, baseUrl: '')->isEnabled());
        self::assertFalse($this->client($http, token: null)->isEnabled());
        self::assertSame('ozon-fetcher', $this->client($http)->service());
    }

    private function client(MockHttpClient $http, ?string $baseUrl = 'http://ozon-fetcher:8080/', ?string $token = self::TOKEN): OzonFetcherCoverageClient
    {
        return new OzonFetcherCoverageClient($http, new ServiceCoverageV1Parser(), new Logger('test', [$this->logs]), $baseUrl, $token);
    }

    private function assertTokenNotLogged(): void
    {
        $records = $this->logs->getRecords();
        self::assertNotEmpty($records);
        self::assertStringNotContainsString(self::TOKEN, json_encode(array_map(static fn ($r): array => $r->toArray(), $records), \JSON_THROW_ON_ERROR));
    }
}
