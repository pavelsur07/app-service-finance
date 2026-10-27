<?php

declare(strict_types=1);

namespace App\Tests\Support\BankSync;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Domain\OAuth\AuthorizationGrant;
use App\BankSync\Domain\OAuth\AuthorizationRequest;
use App\BankSync\Domain\OAuth\BankAuthorizationProviderInterface;
use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Domain\OAuth\Pkce;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\BankIdTokenInvalidException;

/**
 * Заглушка авторизационного сервера банка для тестов. Без состояния между запросами:
 * код авторизации — `ok:<code_challenge>`, и обмен проверяет PKCE так же, как сервер
 * банка: `S256(code_verifier)` обязан совпасть с challenge из URL входа.
 *
 * Обновление настраивается статическими полями (ядро в functional-тестах пересоздаётся).
 */
final class StubBankAuthorizationProvider implements BankAuthorizationProviderInterface
{
    public const AUTHORIZE_URL = 'https://auth.stub.test/oidc/authorize';
    public const ACCESS_TOKEN = 'stub-access-token';
    public const REFRESH_TOKEN = 'stub-refresh-token';
    public const SUBJECT = 'stub-subject-3228e68e';

    public static int $refreshCalls = 0;
    public static ?\Throwable $refreshFailure = null;
    public static int $exchangeCalls = 0;
    /** @var list<string> отозванные refresh-токены */
    public static array $revoked = [];
    public static ?\Throwable $revokeFailure = null;
    /** @var list<string>|null настроенные среды; null — обе */
    public static ?array $environments = null;
    /** @var (\Closure(): void)|null вызывается в момент отзыва */
    public static ?\Closure $duringRevoke = null;
    /** @var (\Closure(): void)|null вызывается в момент обмена кода */
    public static ?\Closure $duringExchange = null;

    public static function reset(): void
    {
        self::$refreshCalls = 0;
        self::$refreshFailure = null;
        self::$exchangeCalls = 0;
        self::$duringExchange = null;
        self::$revoked = [];
        self::$revokeFailure = null;
        self::$duringRevoke = null;
        self::$environments = null;
    }

    public static function codeFor(string $authorizationUrl): string
    {
        parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);

        $challenge = $query['code_challenge'] ?? '';

        return 'ok:'.(\is_string($challenge) ? $challenge : '');
    }

    public function code(): BankCode
    {
        return BankCode::ALFA;
    }

    public function environments(): array
    {
        return self::$environments ?? [BankConnectionFormData::SANDBOX_URL, BankConnectionFormData::PRODUCTION_URL];
    }

    public function authorizationUrl(AuthorizationRequest $request): string
    {
        if (!\in_array($request->baseUrl, $this->environments(), true)) {
            throw new BankAuthorizationNotConfiguredException('alfa', 'ALFA_PROD_CLIENT_ID is not set');
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'stub-client',
            'redirect_uri' => $request->redirectUri,
            'scope' => 'openid transactions',
            'state' => $request->state,
            'nonce' => $request->nonce,
            'code_challenge' => $request->codeChallenge,
            'code_challenge_method' => Pkce::METHOD,
        ]);
    }

    public function exchangeCode(string $baseUrl, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $codeVerifier, string $redirectUri, #[\SensitiveParameter] string $nonce): AuthorizationGrant
    {
        ++self::$exchangeCalls;
        if (null !== self::$duringExchange) {
            (self::$duringExchange)();
        }
        if ('unavailable' === $code) {
            throw new BankApiUnavailableException('alfa', 503);
        }
        if ('bad-id-token' === $code) {
            throw new BankIdTokenInvalidException('signature');
        }
        if (!str_starts_with($code, 'ok:') || !hash_equals(substr($code, 3), Pkce::challengeFor($codeVerifier))) {
            throw new BankAuthorizationRejectedException('alfa', 400, 'invalid_grant');
        }

        return new AuthorizationGrant(new OAuthTokens(self::ACCESS_TOKEN, self::REFRESH_TOKEN, new \DateTimeImmutable('+1 hour')), self::SUBJECT);
    }

    public function refresh(string $baseUrl, #[\SensitiveParameter] string $refreshToken): OAuthTokens
    {
        ++self::$refreshCalls;
        if (null !== self::$refreshFailure) {
            throw self::$refreshFailure;
        }

        return new OAuthTokens('refreshed-access-'.self::$refreshCalls, 'refreshed-refresh-'.self::$refreshCalls, new \DateTimeImmutable('+1 hour'));
    }

    public function revoke(string $baseUrl, #[\SensitiveParameter] string $refreshToken): void
    {
        if (null !== self::$duringRevoke) {
            (self::$duringRevoke)();
        }
        if (null !== self::$revokeFailure) {
            throw self::$revokeFailure;
        }
        self::$revoked[] = $refreshToken;
    }
}
