<?php

declare(strict_types=1);

namespace App\Tests\Support\BankSync;

use App\BankSync\Domain\OAuth\AuthorizationRequest;
use App\BankSync\Domain\OAuth\BankAuthorizationProviderInterface;
use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Domain\OAuth\Pkce;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationRejectedException;

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

    public static int $refreshCalls = 0;
    public static ?\Throwable $refreshFailure = null;

    public static function reset(): void
    {
        self::$refreshCalls = 0;
        self::$refreshFailure = null;
    }

    public static function codeFor(string $authorizationUrl): string
    {
        parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);

        return 'ok:'.(string) ($query['code_challenge'] ?? '');
    }

    public function code(): BankCode
    {
        return BankCode::ALFA;
    }

    public function authorizationUrl(AuthorizationRequest $request): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'stub-client',
            'redirect_uri' => $request->redirectUri,
            'scope' => 'openid transactions',
            'state' => $request->state,
            'code_challenge' => $request->codeChallenge,
            'code_challenge_method' => Pkce::METHOD,
        ]);
    }

    public function exchangeCode(string $baseUrl, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $codeVerifier, string $redirectUri): OAuthTokens
    {
        if ('unavailable' === $code) {
            throw new BankApiUnavailableException('alfa', 503);
        }
        if (!str_starts_with($code, 'ok:') || !hash_equals(substr($code, 3), Pkce::challengeFor($codeVerifier))) {
            throw new BankAuthorizationRejectedException('alfa', 400, 'invalid_grant');
        }

        return new OAuthTokens(self::ACCESS_TOKEN, self::REFRESH_TOKEN, new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+365 days'));
    }

    public function refresh(string $baseUrl, #[\SensitiveParameter] string $refreshToken): OAuthTokens
    {
        ++self::$refreshCalls;
        if (null !== self::$refreshFailure) {
            throw self::$refreshFailure;
        }

        return new OAuthTokens('refreshed-access-'.self::$refreshCalls, 'refreshed-refresh-'.self::$refreshCalls, new \DateTimeImmutable('+1 hour'));
    }
}
