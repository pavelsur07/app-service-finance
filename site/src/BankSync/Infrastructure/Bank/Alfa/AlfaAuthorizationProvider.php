<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Domain\OAuth\AuthorizationGrant;
use App\BankSync\Domain\OAuth\AuthorizationRequest;
use App\BankSync\Domain\OAuth\BankAuthorizationProviderInterface;
use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Domain\OAuth\Pkce;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankIdTokenInvalidException;
use App\BankSync\Infrastructure\OAuth\IdTokenVerifier;
use App\BankSync\Infrastructure\OAuth\OAuthTokenEndpointClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Вход через Alfa ID (B2B, Authorization Code + PKCE + OpenID Connect). Token, revoke —
 * на хосте API среды по mTLS (`bank_sync.alfa.token_endpoint_client` поверх
 * `bank_sync.alfa.http_client`); страница входа — `id-sandbox` / `id`.
 *
 * Scope минимальный: `openid`, выписка (`transactions`) и список счетов; `userinfo` и
 * claims с ПДн представителя не запрашиваются.
 */
final readonly class AlfaAuthorizationProvider implements BankAuthorizationProviderInterface
{
    /** Список счетов — `obru_accounts_le` (стандарт Банка России); окончательный выбор — Stage 7. */
    public const SCOPE = 'openid transactions obru_accounts_le';

    public function __construct(
        private AlfaIdConfig $config,
        #[Autowire(service: 'bank_sync.alfa.token_endpoint_client')]
        private OAuthTokenEndpointClient $tokenEndpoint,
        private IdTokenVerifier $idTokenVerifier,
    ) {
    }

    public function code(): BankCode
    {
        return BankCode::ALFA;
    }

    public function environments(): array
    {
        return $this->config->configuredBaseUrls();
    }

    public function authorizationUrl(AuthorizationRequest $request): string
    {
        $environment = $this->config->environment($request->baseUrl);

        // RFC 3986: пробелы в scope — `%20`, как в примерах банка, а не `+`.
        return $environment->authorizeUrl.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $environment->clientId,
            'redirect_uri' => $request->redirectUri,
            'scope' => self::SCOPE,
            'state' => $request->state,
            'nonce' => $request->nonce,
            'code_challenge' => $request->codeChallenge,
            'code_challenge_method' => Pkce::METHOD,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    public function exchangeCode(
        string $baseUrl,
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] string $codeVerifier,
        string $redirectUri,
        #[\SensitiveParameter] string $nonce,
    ): AuthorizationGrant {
        $environment = $this->config->environment($baseUrl);
        $response = $this->tokenEndpoint->requestTokens(BankCode::ALFA->value, $environment->tokenUrl(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
            'client_id' => $environment->clientId,
            'client_secret' => $environment->clientSecret,
        ]);
        if (null === $response->idToken) {
            // scope `openid` запрошен — без id_token не знаем, кто дал согласие.
            throw new BankIdTokenInvalidException('missing');
        }

        $subject = $this->idTokenVerifier->verify(
            $response->idToken,
            $environment->signingCertificates,
            $environment->issuers,
            $environment->clientId,
            $nonce,
        );

        return new AuthorizationGrant($response->tokens, $subject);
    }

    public function refresh(string $baseUrl, #[\SensitiveParameter] string $refreshToken): OAuthTokens
    {
        $environment = $this->config->environment($baseUrl);

        return $this->tokenEndpoint->requestTokens(BankCode::ALFA->value, $environment->tokenUrl(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $environment->clientId,
            'client_secret' => $environment->clientSecret,
        ])->tokens;
    }

    public function revoke(string $baseUrl, #[\SensitiveParameter] string $refreshToken): void
    {
        $environment = $this->config->environment($baseUrl);

        $this->tokenEndpoint->revokeToken(BankCode::ALFA->value, $environment->revokeUrl(), [
            'token' => $refreshToken,
            'token_type_hint' => 'refresh_token',
            'client_id' => $environment->clientId,
            'client_secret' => $environment->clientSecret,
        ]);
    }
}
