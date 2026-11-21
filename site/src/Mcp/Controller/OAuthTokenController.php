<?php

declare(strict_types=1);

namespace App\Mcp\Controller;

use App\Company\Security\PublicAccess;
use App\Mcp\Application\McpOAuthTokenAction;
use App\Mcp\Infrastructure\OAuth\OAuthHttp;
use App\Mcp\Security\McpOAuthConfig;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[PublicAccess]
final readonly class OAuthTokenController
{
    public function __construct(
        private McpOAuthConfig $config,
        private McpOAuthTokenAction $tokens,
        #[Autowire(service: 'limiter.user_mcp_oauth')] private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/oauth/token', name: 'mcp_oauth_token', defaults: ['revoke' => false], methods: ['POST'])]
    #[Route('/oauth/revoke', name: 'mcp_oauth_revoke', defaults: ['revoke' => true], methods: ['POST'])]
    public function __invoke(Request $request, bool $revoke): Response
    {
        $this->config->requireCanonicalRequest($request);
        $limit = $this->limiter->create('token:'.$request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return OAuthHttp::rateLimited($limit);
        }

        try {
            $body = OAuthHttp::parameters($request);
            if ($revoke) {
                $this->tokens->revoke($body);

                return new Response('', 200, OAuthHttp::PRIVATE_HEADERS);
            }

            return OAuthHttp::privateResponse($this->tokens->issue($body));
        } catch (OAuthServerException $exception) {
            return OAuthHttp::error($exception);
        }
    }
}
