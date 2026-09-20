<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Infrastructure\Api;

use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

final class MoySkladClientTest extends TestCase
{
    public function testChecksEmployeeWithBoundedSecureRequestAndReturnsOnlyAccount(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/remap/1.2/context/employee', $url);
            self::assertContains('Authorization: Bearer test-secret', $options['normalized_headers']['authorization']);
            self::assertContains('Accept: application/json;charset=utf-8', $options['normalized_headers']['accept']);
            self::assertContains('Accept-Encoding: gzip', $options['normalized_headers']['accept-encoding']);
            self::assertSame(10.0, (float) $options['timeout']);
            self::assertSame(10.0, (float) $options['max_duration']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse('{"accountId":"d82c0727-2f5b-4b78-9847-a2cb36660c13","name":"private-person","token":"test-secret"}');
        });
        $result = (new MoySkladClient($http, 'https://example.test/remap/1.2/'))->check('test-secret');

        self::assertSame(ConnectionCheckStatus::CONNECTED, $result->status);
        self::assertSame('d82c0727-2f5b-4b78-9847-a2cb36660c13', $result->accountId);
        self::assertSame(['status', 'accountId'], array_keys(get_object_vars($result)));
        self::assertStringNotContainsString('private-person', serialize($result));
        self::assertStringNotContainsString('test-secret', serialize($result));
    }

    #[DataProvider('responses')]
    public function testMapsResponsesToSafeOutcomes(int $status, string $body, string $expected): void
    {
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
        $result = (new MoySkladClient($http, 'https://example.test'))->check('test-secret');

        self::assertSame($expected, $result->status->value);
        self::assertNull($result->accountId);
        self::assertStringNotContainsString('test-secret', $result->status->message());
        self::assertStringNotContainsString('private-person', serialize($result));
        self::assertNotSame('', $result->status->message());
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function responses(): iterable
    {
        yield 'invalid token' => [401, '{"errors":[{"error":"test-secret"}]}', 'invalid_token'];
        yield 'forbidden' => [403, '{}', 'forbidden'];
        yield 'tariff before forbidden' => [403, '{"errors":[{"code":47000,"error":"private-person"}]}', 'tariff_restricted'];
        yield 'unsupported before unauthorized' => [401, '{"errors":[{"code":55006}]}', 'unsupported_token'];
        yield 'unsupported before forbidden' => [403, '{"errors":[{"code":55006}]}', 'unsupported_token'];
        yield 'tariff on bad request' => [400, '{"errors":[{"code":47000}]}', 'tariff_restricted'];
        yield 'rate limit' => [429, 'not-json', 'rate_limited'];
        yield 'server failure' => [503, 'not-json', 'unavailable'];
        yield 'bad json' => [200, '{', 'invalid_response'];
        yield 'missing account' => [200, '{"id":"d82c0727-2f5b-4b78-9847-a2cb36660c13"}', 'invalid_response'];
        yield 'invalid account' => [200, '{"accountId":"private-person"}', 'invalid_response'];
        yield 'array account' => [200, '{"accountId":[]}', 'invalid_response'];
        yield 'scalar json' => [200, 'true', 'invalid_response'];
        yield 'list json' => [200, '[]', 'invalid_response'];
        yield 'null json' => [200, 'null', 'invalid_response'];
        yield 'redirect' => [302, '{"accountId":"d82c0727-2f5b-4b78-9847-a2cb36660c13"}', 'invalid_response'];
        yield 'unknown client error' => [400, '{"errors":"unexpected"}', 'invalid_response'];
        yield 'malformed errors' => [400, '{"errors":[null,"bad",true]}', 'invalid_response'];
    }

    public function testTransportFailureDoesNotExposeExceptionOrRetry(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('test-secret private-person');
        });
        $result = (new MoySkladClient($http, 'https://example.test'))->check('test-secret');

        self::assertSame(ConnectionCheckStatus::UNAVAILABLE, $result->status);
        self::assertNull($result->accountId);
        self::assertStringNotContainsString('test-secret', serialize($result));
    }

    #[DataProvider('invalidTokens')]
    public function testRejectsMalformedTokenWithoutHttpRequest(string $token): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('Invalid token must never reach HTTP transport.');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('log');
        $result = (new MoySkladClient($http, 'https://example.test', $logger))->check($token);
        self::assertSame(ConnectionCheckStatus::INVALID_TOKEN, $result->status);
        self::assertNull($result->accountId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'cyrillic' => ['private-токен'];
        yield 'non-breaking space' => ["private-\u{00A0}token"];
        yield 'zero-width space' => ["private-\u{200B}token"];
        yield 'over limit' => [str_repeat('a', 8193)];
        yield 'space' => ['token secret'];
        yield 'newline' => ["token\nsecret"];
        yield 'null byte' => ["token\0secret"];
        yield 'delete' => ["token\x7fsecret"];
    }

    #[DataProvider('loggingResponses')]
    public function testLogsOnlySafeRequestMetadata(bool $transportFailure, int $httpStatus, string $level): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->with($level, 'MoySklad connection check request', self::callback(static function (array $context) use ($transportFailure, $httpStatus): bool {
            self::assertSame(['method', 'url', 'httpStatus', 'durationMs'], array_keys($context));
            self::assertSame('GET', $context['method']);
            self::assertSame('https://example.test/remap/1.2/context/employee', $context['url']);
            self::assertSame($transportFailure ? null : $httpStatus, $context['httpStatus']);
            self::assertIsFloat($context['durationMs']);
            self::assertGreaterThanOrEqual(0, $context['durationMs']);
            self::assertStringNotContainsString('private-', serialize($context));

            return true;
        }));
        $http = new MockHttpClient(static function () use ($transportFailure, $httpStatus): MockResponse {
            if ($transportFailure) {
                throw new TransportException('private-token private-person private-response');
            }

            return new MockResponse('{"accountId":"d82c0727-2f5b-4b78-9847-a2cb36660c13","name":"private-person","errors":[{"error":"private-response"}]}', ['http_code' => $httpStatus]);
        });
        $client = new MoySkladClient($http, 'https://example.test/remap/1.2', $logger);
        $client->check('private-token');
    }

    /** @return iterable<string, array{bool, int, string}> */
    public static function loggingResponses(): iterable
    {
        yield 'connected' => [false, 200, 'info'];
        yield 'rejected' => [false, 401, 'warning'];
        yield 'unavailable' => [false, 503, 'warning'];
        yield 'transport failure' => [true, 0, 'warning'];
    }

    public function testDisablesRetriesOnRetryableTransport(): void
    {
        $requests = 0;
        $http = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => 503]);
        });
        $client = new MoySkladClient(new RetryableHttpClient($http), 'https://example.test');

        self::assertSame(ConnectionCheckStatus::UNAVAILABLE, $client->check('test-secret')->status);
        self::assertSame(1, $requests);
    }
}
