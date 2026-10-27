<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\OAuth;

use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Infrastructure\OAuth\OAuthTokenEndpointClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OAuthTokenEndpointClientTest extends TestCase
{
    private const TOKEN_URL = 'https://auth.bank.example/oidc/token';
    private const REVOKE_URL = 'https://auth.bank.example/oidc/revoke';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testParsesTokensAndPostsForm(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['body']];

            return new MockResponse('{"access_token":"new-access","refresh_token":"new-refresh","expires_in":3600,"token_type":"Bearer"}');
        });

        $response = $this->client($http)->requestTokens('alfa', self::TOKEN_URL, ['grant_type' => 'refresh_token', 'refresh_token' => 'old-refresh', 'client_secret' => 'app-secret']);
        $tokens = $response->tokens;

        self::assertSame('new-access', $tokens->accessToken);
        self::assertSame('new-refresh', $tokens->refreshToken);
        self::assertEquals(new \DateTimeImmutable('2026-10-26T13:00:00+00:00'), $tokens->accessExpiresAt);
        self::assertSame('POST', $requests[0][0]);
        self::assertSame(self::TOKEN_URL, $requests[0][1]);
        self::assertStringContainsString('grant_type=refresh_token', $requests[0][2]);
        self::assertNull($response->idToken);
        $this->assertNothingSecretLogged();
    }

    public function testIdTokenIsReturnedUnverifiedAndNotLogged(): void
    {
        $response = $this->client(new MockHttpClient(new MockResponse('{"access_token":"new-access","refresh_token":"new-refresh","id_token":"header.payload.signature-leak","expires_in":3600}')))
            ->requestTokens('alfa', self::TOKEN_URL, ['grant_type' => 'authorization_code', 'code' => 'leak']);

        self::assertSame('header.payload.signature-leak', $response->idToken);
        $this->assertNothingSecretLogged();
    }

    public function testRevokePostsFormAndAcceptsOk(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['body']];

            return new MockResponse('{}');
        });

        $this->client($http)->revokeToken('alfa', self::REVOKE_URL, ['token' => 'old-refresh', 'token_type_hint' => 'refresh_token', 'client_secret' => 'app-secret']);

        self::assertSame(['POST', self::REVOKE_URL], [$requests[0][0], $requests[0][1]]);
        self::assertStringContainsString('token_type_hint=refresh_token', $requests[0][2]);
        self::assertSame('Bank OAuth token revoked', $this->logger->records[0][1]);
        $this->assertNothingSecretLogged();
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function revokeFailures(): iterable
    {
        yield '400 — не «войдите заново», а отказ' => [400, BankApiRequestFailedException::class];
        yield '401 invalid_client' => [401, BankApiRequestFailedException::class];
        yield '503' => [503, BankApiUnavailableException::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('revokeFailures')]
    public function testRevokeFailures(int $status, string $expected): void
    {
        $this->expectException($expected);

        $this->client(new MockHttpClient(new MockResponse('{"error":"invalid_client"}', ['http_code' => $status])))
            ->revokeToken('alfa', self::REVOKE_URL, ['token' => 'old-refresh']);
    }

    public function testRefreshTokenIsOptional(): void
    {
        $tokens = $this->client(new MockHttpClient(new MockResponse('{"access_token":"a","expires_in":60}')))->requestTokens('alfa', self::TOKEN_URL, ['grant_type' => 'refresh_token'])->tokens;

        self::assertNull($tokens->refreshToken);
    }

    /**
     * @return iterable<string, array{int, string, class-string<\Throwable>}>
     */
    public static function failures(): iterable
    {
        yield 'invalid_grant 400' => [400, '{"error":"invalid_grant","error_description":"Refresh token reused for client 7701234567"}', BankAuthorizationRejectedException::class];
        yield '401' => [401, '{"error":"invalid_client"}', BankAuthorizationRejectedException::class];
        yield '403 config' => [403, '{}', BankApiRequestFailedException::class];
        yield '429' => [429, '', BankApiUnavailableException::class];
        yield '503' => [503, '<html>down</html>', BankApiUnavailableException::class];
        yield '200 without access_token' => [200, '{"token_type":"Bearer"}', BankApiRequestFailedException::class];
        yield '200 not json' => [200, 'oops', BankApiRequestFailedException::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('failures')]
    public function testFailures(int $status, string $body, string $expected): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse($body, ['http_code' => $status])));

        try {
            $client->requestTokens('alfa', self::TOKEN_URL, ['grant_type' => 'refresh_token', 'refresh_token' => 'old-refresh']);
            self::fail('exception expected');
        } catch (\Throwable $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertStringNotContainsString('7701234567', $exception->getMessage(), 'описание ошибки банка не попадает в сообщение');
        }
        $this->assertNothingSecretLogged();
    }

    public function testRejectionKeepsOnlyCodeShapedOauthError(): void
    {
        try {
            $this->client(new MockHttpClient(new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])))->requestTokens('alfa', self::TOKEN_URL, ['grant_type' => 'authorization_code']);
            self::fail('exception expected');
        } catch (BankAuthorizationRejectedException $exception) {
            self::assertSame('invalid_grant', $exception->getOauthError());
            self::assertSame(400, $exception->getStatusCode());
        }

        self::assertNull((new BankAuthorizationRejectedException('alfa', 400, 'Token for 7701234567 <b>'))->getOauthError());
    }

    public function testTransportErrorIsTemporary(): void
    {
        $this->expectException(BankApiUnavailableException::class);

        $this->client(new MockHttpClient(new MockResponse('', ['error' => 'Connection refused for https://auth.bank.example/oidc/token?code=leak'])))
            ->requestTokens('alfa', self::TOKEN_URL, ['grant_type' => 'authorization_code', 'code' => 'leak']);
    }

    private function client(MockHttpClient $http): OAuthTokenEndpointClient
    {
        return new OAuthTokenEndpointClient($http, new MockClock('2026-10-26 12:00:00', 'UTC'), $this->logger);
    }

    private function assertNothingSecretLogged(): void
    {
        $logged = json_encode($this->logger->records, \JSON_THROW_ON_ERROR);
        foreach (['old-refresh', 'new-refresh', 'new-access', 'app-secret', '7701234567', 'leak'] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
