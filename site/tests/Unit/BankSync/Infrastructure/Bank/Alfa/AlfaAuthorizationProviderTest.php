<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Domain\OAuth\AuthorizationRequest;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\BankIdTokenInvalidException;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaAuthorizationProvider;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaIdConfig;
use App\BankSync\Infrastructure\OAuth\IdTokenVerifier;
use App\BankSync\Infrastructure\OAuth\OAuthTokenEndpointClient;
use App\Tests\Support\BankSync\TestCertificates;
use App\Tests\Support\BankSync\TestIdTokenSigner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AlfaAuthorizationProviderTest extends TestCase
{
    private const SANDBOX = BankConnectionFormData::SANDBOX_URL;
    private const CLIENT_ID = '0cee0683-85ae-49f2-a63d-29f97aad1911';
    private const CLIENT_SECRET = 'Qwerty1234567890Qwerty!';
    private const NONCE = 'a3f1c2d4e5b6a7c8d9e0f1a2b3c4d5e6';
    private const SUBJECT = '3228e68e-cc34-4b82-b43d-49112b5d9d3e';
    private const REDIRECT_URI = 'https://app.example/bank-connections/oauth/callback';

    private TestCertificates $files;
    private TestIdTokenSigner $signer;
    private MockClock $clock;
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->files = new TestCertificates();
        $this->signer = new TestIdTokenSigner();
        $this->clock = new MockClock();
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    public function testEnvironmentsAreOnlyThoseWithAllThreeVariables(): void
    {
        self::assertSame([], $this->config(sandbox: false)->configuredBaseUrls());
        self::assertSame([self::SANDBOX], $this->config()->configuredBaseUrls());
        self::assertSame([], (new AlfaIdConfig(self::CLIENT_ID, '', '/some/cert.pem', null, null, null))->configuredBaseUrls(), 'без секрета среда не настроена');
    }

    public function testAuthorizationUrlCarriesClientScopeStateNonceAndPkce(): void
    {
        $url = $this->provider()->authorizationUrl(new AuthorizationRequest(self::SANDBOX, 'state-uuid', 'challenge-43', self::REDIRECT_URI, self::NONCE));

        self::assertStringStartsWith('https://id-sandbox.alfabank.ru/oidc/authorize?', $url);
        self::assertStringContainsString('scope=openid%20transactions%20obru_accounts_le', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'openid transactions obru_accounts_le',
            'state' => 'state-uuid',
            'nonce' => self::NONCE,
            'code_challenge' => 'challenge-43',
            'code_challenge_method' => 'S256',
        ], $query);
        self::assertStringNotContainsString(self::CLIENT_SECRET, $url);
    }

    public function testUnconfiguredEnvironmentNamesOnlyTheVariable(): void
    {
        try {
            $this->provider()->authorizationUrl(new AuthorizationRequest(BankConnectionFormData::PRODUCTION_URL, 's', 'c', self::REDIRECT_URI, self::NONCE));
            self::fail('expected not configured');
        } catch (BankAuthorizationNotConfiguredException $exception) {
            self::assertStringContainsString(AlfaIdConfig::PROD_CLIENT_ID_ENV, $exception->getMessage());
        }
    }

    public function testUnreadableSigningCertificateIsAConfigurationErrorWithoutPath(): void
    {
        $path = $this->files->write('broken.pem', "-----BEGIN CERTIFICATE-----\nnot-base64\n-----END CERTIFICATE-----\n");
        $config = new AlfaIdConfig(self::CLIENT_ID, self::CLIENT_SECRET, $path, null, null, null);

        try {
            $config->environment(self::SANDBOX);
            self::fail('expected not configured');
        } catch (BankAuthorizationNotConfiguredException $exception) {
            self::assertStringContainsString(AlfaIdConfig::SANDBOX_SIGNING_CERT_PATH_ENV, $exception->getMessage());
            self::assertStringNotContainsString($path, $exception->getMessage());
        }
    }

    public function testExchangePostsFormOverAlfaTokenEndpointAndReturnsVerifiedSubject(): void
    {
        $grant = $this->provider($this->tokenResponse(['id_token' => $this->idToken()]))
            ->exchangeCode(self::SANDBOX, 'auth-code', 'verifier-43', self::REDIRECT_URI, self::NONCE);

        self::assertSame(self::SUBJECT, $grant->externalSubject);
        self::assertSame('alfa-access', $grant->tokens->accessToken);
        self::assertSame('alfa-refresh', $grant->tokens->refreshToken);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://sandbox.alfabank.ru/oidc/token', $this->requests[0]['url']);
        parse_str($this->requests[0]['body'], $form);
        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'auth-code',
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => 'verifier-43',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
        ], $form);
    }

    public function testExchangeWithoutIdTokenIsRejected(): void
    {
        $this->expectException(BankIdTokenInvalidException::class);
        $this->expectExceptionMessage('missing');

        $this->provider($this->tokenResponse())->exchangeCode(self::SANDBOX, 'auth-code', 'verifier', self::REDIRECT_URI, self::NONCE);
    }

    public function testExchangeWithIdTokenForAnotherNonceIsRejected(): void
    {
        $this->expectException(BankIdTokenInvalidException::class);
        $this->expectExceptionMessage('nonce');

        $this->provider($this->tokenResponse(['id_token' => $this->idToken(['nonce' => 'replayed'])]))
            ->exchangeCode(self::SANDBOX, 'auth-code', 'verifier', self::REDIRECT_URI, self::NONCE);
    }

    public function testExchangeWithIdTokenSignedByUnknownKeyIsRejected(): void
    {
        $foreign = (new TestIdTokenSigner())->sign($this->claims());

        $this->expectException(BankIdTokenInvalidException::class);
        $this->expectExceptionMessage('signature');

        $this->provider($this->tokenResponse(['id_token' => $foreign]))->exchangeCode(self::SANDBOX, 'auth-code', 'verifier', self::REDIRECT_URI, self::NONCE);
    }

    public function testInvalidGrantMeansSignInAgain(): void
    {
        $this->expectException(BankAuthorizationRejectedException::class);

        $this->provider(new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400]))
            ->exchangeCode(self::SANDBOX, 'expired-code', 'verifier', self::REDIRECT_URI, self::NONCE);
    }

    public function testInvalidClientIsOurConfiguration(): void
    {
        $this->expectException(BankApiRequestFailedException::class);

        $this->provider(new MockResponse('{"error":"invalid_client"}', ['http_code' => 401]))
            ->refresh(self::SANDBOX, 'refresh');
    }

    public function testRefreshPostsRefreshGrant(): void
    {
        $tokens = $this->provider($this->tokenResponse())->refresh(self::SANDBOX, 'old-refresh');

        self::assertSame('alfa-refresh', $tokens->refreshToken);
        parse_str($this->requests[0]['body'], $form);
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'old-refresh', 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET], $form);
    }

    public function testRevokePostsRefreshTokenToRevokeEndpoint(): void
    {
        $this->provider(new MockResponse('{}'))->revoke(self::SANDBOX, 'old-refresh');

        self::assertSame('https://sandbox.alfabank.ru/oidc/revoke', $this->requests[0]['url']);
        parse_str($this->requests[0]['body'], $form);
        self::assertSame(['token' => 'old-refresh', 'token_type_hint' => 'refresh_token', 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET], $form);
    }

    private function provider(?MockResponse $response = null): AlfaAuthorizationProvider
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \is_string($options['body'] ?? null) ? $options['body'] : ''];

            return $response ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new AlfaAuthorizationProvider(
            $this->config(),
            new OAuthTokenEndpointClient($http, $this->clock, new NullLogger()),
            new IdTokenVerifier($this->clock),
        );
    }

    private function config(bool $sandbox = true): AlfaIdConfig
    {
        $certificatePath = $this->files->write('alfa-id-signing.pem', $this->signer->certificatePem);

        return $sandbox
            ? new AlfaIdConfig(self::CLIENT_ID, self::CLIENT_SECRET, $certificatePath, null, null, null)
            : new AlfaIdConfig(null, null, null, null, null, null);
    }

    /**
     * @param array<string, string> $extra
     */
    private function tokenResponse(array $extra = []): MockResponse
    {
        return new MockResponse(json_encode(['access_token' => 'alfa-access', 'refresh_token' => 'alfa-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer'] + $extra, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $override
     */
    private function idToken(array $override = []): string
    {
        return $this->signer->sign($this->claims($override));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function claims(array $override = []): array
    {
        $now = $this->clock->now()->getTimestamp();

        return array_replace([
            'sub' => self::SUBJECT,
            'aud' => self::CLIENT_ID,
            'azp' => self::CLIENT_ID,
            'iss' => 'https://id-sandbox.alfabank.ru/oidc',
            'exp' => $now + 3600,
            'iat' => $now,
            'nonce' => self::NONCE,
        ], $override);
    }
}
