<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use App\Mcp\Security\McpEntitlements;
use App\Mcp\Security\McpOAuthConfig;
use App\Mcp\Security\McpScope;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

final readonly class OAuthRepositories implements AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, ClientRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface
{
    public function __construct(
        private McpOAuthStore $store,
        private McpEntitlements $entitlements,
        private McpOAuthConfig $config,
    ) {
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $redirects = $this->config->redirectsFor($clientIdentifier);

        return '' === $clientIdentifier || [] === $redirects ? null : new OAuthClient($clientIdentifier, $redirects);
    }

    public function validateClient(string $clientIdentifier, #[\SensitiveParameter] ?string $clientSecret, ?string $grantType): bool
    {
        return (null === $clientSecret || '' === $clientSecret) && null !== $this->getClientEntity($clientIdentifier);
    }

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        $scope = McpScope::tryFrom($identifier);

        return null !== $scope ? new OAuthScope($scope) : null;
    }

    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        if ([] === $scopes) {
            throw OAuthServerException::invalidScope('');
        }

        return $scopes;
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new OAuthAccessToken($this->config->origin(), $this->config->resource());
        $token->setClient($clientEntity);
        if (null === $userIdentifier || '' === $userIdentifier) {
            throw new \LogicException('A connection is required.');
        }
        $token->setUserIdentifier($userIdentifier);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->store->putCredential('access', $accessTokenEntity->getIdentifier(), $accessTokenEntity->getUserIdentifier() ?? throw new \LogicException('A connection is required.'), $accessTokenEntity->getExpiryDateTime());
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->store->consumeCredential('access', $tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return null === $this->store->activeConnectionForCredential('access', $tokenId);
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new OAuthCode();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->store->putCredential('code', $authCodeEntity->getIdentifier(), $authCodeEntity->getUserIdentifier() ?? throw new \LogicException('A connection is required.'), $authCodeEntity->getExpiryDateTime());
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->store->consumeCredential('code', $codeId);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        return !$this->canIssueFrom('code', $codeId);
    }

    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new OAuthRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $connectionId = $refreshTokenEntity->getAccessToken()->getUserIdentifier() ?? throw new \LogicException('A connection is required.');
        $this->store->putCredential('refresh', $refreshTokenEntity->getIdentifier(), $connectionId, $refreshTokenEntity->getExpiryDateTime());
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $this->store->consumeCredential('refresh', $tokenId);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        return !$this->canIssueFrom('refresh', $tokenId);
    }

    private function canIssueFrom(string $kind, string $tokenId): bool
    {
        $connection = $this->store->activeConnectionForCredential($kind, $tokenId, lock: true);

        return null !== $connection && $this->config->resource() === $connection->resource
            && [] !== $this->config->redirectsFor($connection->clientId)
            && [] !== $this->entitlements->allowedScopes($connection->companyId, $connection->userId, $connection->scopes);
    }
}
