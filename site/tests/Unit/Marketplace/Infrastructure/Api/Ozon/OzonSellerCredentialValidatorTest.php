<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Infrastructure\Api\Ozon\OzonCredentialValidationStatus;
use App\Marketplace\Infrastructure\Api\Ozon\OzonSellerCredentialValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class OzonSellerCredentialValidatorTest extends TestCase
{
    public function testSuccessMapsToValidAndUsesProductLimitProbe(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"result":{"total":{"limit":1000,"usage":0}}}', ['http_code' => 200]);
        });

        $result = $this->validator($http)->validate('client-1', 'api-1');

        self::assertTrue($result->isValid());
        self::assertSame(OzonCredentialValidationStatus::VALID, $result->status);
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/v4/product/info/limit', $captured['url']);
        self::assertHeaderValue('client-1', $captured['options'], 'client-id');
        self::assertHeaderValue('api-1', $captured['options'], 'api-key');
    }

    #[DataProvider('invalidCredentialsResponses')]
    public function testInvalidCredentialResponsesMapToInvalidCredentials(int $statusCode, string $body): void
    {
        $result = $this->validator(new MockHttpClient(new MockResponse($body, ['http_code' => $statusCode])))
            ->validate('client-1', 'api-1');

        self::assertSame(OzonCredentialValidationStatus::INVALID_CREDENTIALS, $result->status);
        self::assertFalse($result->isValid());
        self::assertSame($statusCode, $result->statusCode);
    }

    public function testRateLimitMapsToRateLimited(): void
    {
        $result = $this->validator(new MockHttpClient(new MockResponse('{"message":"rate"}', ['http_code' => 429])))
            ->validate('client-1', 'api-1');

        self::assertSame(OzonCredentialValidationStatus::RATE_LIMITED, $result->status);
        self::assertFalse($result->isValid());
    }

    public function testServerErrorMapsToTemporaryError(): void
    {
        $result = $this->validator(new MockHttpClient(new MockResponse('{"message":"server"}', ['http_code' => 500])))
            ->validate('client-1', 'api-1');

        self::assertSame(OzonCredentialValidationStatus::TEMPORARY_ERROR, $result->status);
        self::assertFalse($result->isValid());
    }

    public function testNetworkErrorMapsToTemporaryError(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new class('network down') extends \RuntimeException implements TransportExceptionInterface {
            };
        });

        $result = $this->validator($http)->validate('client-1', 'api-1');

        self::assertSame(OzonCredentialValidationStatus::TEMPORARY_ERROR, $result->status);
        self::assertFalse($result->isValid());
    }

    public function testUnexpectedClientResponseMapsToUnexpectedError(): void
    {
        $result = $this->validator(new MockHttpClient(new MockResponse('{"message":"bad request"}', ['http_code' => 400])))
            ->validate('client-1', 'api-1');

        self::assertSame(OzonCredentialValidationStatus::UNEXPECTED_ERROR, $result->status);
        self::assertFalse($result->isValid());
    }

    public function testMissingCredentialsMapToInvalidCredentialsWithoutHttpRequest(): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('HTTP request must not be sent for incomplete credentials.');
        });

        $result = $this->validator($http)->validate('', 'api-1');

        self::assertSame(OzonCredentialValidationStatus::INVALID_CREDENTIALS, $result->status);
        self::assertFalse($result->isValid());
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function invalidCredentialsResponses(): iterable
    {
        yield 'unauthorized' => [401, '{"message":"unauthorized"}'];
        yield 'forbidden' => [403, '{"message":"forbidden"}'];
        yield 'not found invalid api key' => [404, '{"message":"invalid api key"}'];
    }

    private function validator(MockHttpClient $http): OzonSellerCredentialValidator
    {
        return new OzonSellerCredentialValidator($http, new NullLogger());
    }

    private static function assertHeaderValue(string $expected, array $options, string $normalizedName): void
    {
        $normalized = $options['normalized_headers'][$normalizedName][0] ?? null;
        self::assertIsString($normalized);
        self::assertSame($expected, trim((string) preg_replace('/^[^:]+:/', '', $normalized)));
    }
}
