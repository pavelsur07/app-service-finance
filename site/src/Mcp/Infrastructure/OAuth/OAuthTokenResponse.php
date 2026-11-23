<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Psr\Http\Message\ResponseInterface;

final class OAuthTokenResponse extends BearerTokenResponse
{
    public function __construct(private readonly McpOAuthStore $store)
    {
    }

    public function generateHttpResponse(ResponseInterface $response): ResponseInterface
    {
        $response = parent::generateHttpResponse($response);
        $payload = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($payload) || !\is_string($payload['access_token'] ?? null) || !\is_string($payload['refresh_token'] ?? null)) {
            throw new \LogicException('Incomplete MCP OAuth token response.');
        }

        $this->store->bindWireToken($this->accessToken->getIdentifier(), $payload['access_token']);
        $this->store->bindWireToken($this->refreshToken->getIdentifier(), $payload['refresh_token']);

        return $response;
    }

    /** @return array{scope: string} */
    protected function getExtraParams(#[\SensitiveParameter] AccessTokenEntityInterface $accessToken): array
    {
        return ['scope' => implode(' ', array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $accessToken->getScopes()))];
    }
}
