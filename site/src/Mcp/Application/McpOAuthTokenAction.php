<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use App\Mcp\Infrastructure\OAuth\McpOAuthServer;
use App\Mcp\Infrastructure\OAuth\McpOAuthStore;
use App\Mcp\Security\McpOAuthConfig;
use Doctrine\DBAL\Connection;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\Response;

final readonly class McpOAuthTokenAction
{
    public function __construct(
        private Connection $db,
        private McpOAuthConfig $config,
        private McpOAuthServer $oauth,
        private McpOAuthStore $store,
    ) {
    }

    /** @param array<string, string> $body */
    public function issue(#[\SensitiveParameter] array $body): Response
    {
        $this->requireRegisteredClient($body);
        if (($body['resource'] ?? $this->config->resource()) !== $this->config->resource()) {
            throw new OAuthServerException('The requested resource is invalid.', 0, 'invalid_target', 400);
        }

        $result = $this->db->transactional(function () use ($body): ?Response {
            if ('refresh_token' === ($body['grant_type'] ?? null) && isset($body['refresh_token'])
                && $this->store->revokeReplayedRefresh($body['client_id'], $body['refresh_token'])) {
                // The revocation commits before the rejected refresh is reported to the client.
                return null;
            }

            return $this->oauth->issueToken($body);
        });

        return $result ?? throw OAuthServerException::invalidGrant();
    }

    /** @param array<string, string> $body */
    public function revoke(#[\SensitiveParameter] array $body): void
    {
        $this->requireRegisteredClient($body);
        if (!isset($body['token']) || '' === $body['token']) {
            throw OAuthServerException::invalidRequest('token');
        }

        $this->store->revokeByWireToken($body['client_id'], $this->config->resource(), $body['token']);
    }

    /** @param array<string, string> $body */
    private function requireRegisteredClient(#[\SensitiveParameter] array $body): void
    {
        if ([] === $this->config->redirectsFor($body['client_id'] ?? '') || isset($body['client_secret'])) {
            throw new OAuthServerException('Client authentication failed.', 0, 'invalid_client', 401);
        }
    }
}
