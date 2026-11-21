<?php

declare(strict_types=1);

namespace App\Mcp\Controller;

use App\Company\Security\PublicAccess;
use App\Mcp\Security\McpOAuthConfig;
use App\Mcp\Security\McpScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[PublicAccess]
final readonly class OAuthMetadataController
{
    public function __construct(private McpOAuthConfig $config)
    {
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'mcp_oauth_server_metadata', defaults: ['server' => true], methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'mcp_oauth_resource_metadata', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource', name: 'mcp_oauth_root_resource_metadata', methods: ['GET'])]
    public function __invoke(Request $request, bool $server = false): JsonResponse
    {
        $this->config->requireCanonicalRequest($request);
        $origin = $this->config->origin();
        $scopes = array_column(McpScope::cases(), 'value');

        $data = $server ? [
            'issuer' => $origin,
            'authorization_endpoint' => $origin.'/oauth/authorize',
            'token_endpoint' => $origin.'/oauth/token',
            'revocation_endpoint' => $origin.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => $scopes,
        ] : [
            'resource' => $this->config->resource(),
            'authorization_servers' => [$origin],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => $scopes,
        ];

        return new JsonResponse($data);
    }
}
