<?php

declare(strict_types=1);

namespace App\Mcp\Controller;

use App\Company\Security\PublicAccess;
use App\Mcp\Application\McpConsentAction;
use App\Mcp\Infrastructure\OAuth\OAuthHttp;
use App\Mcp\Security\McpOAuthConfig;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsController]
#[PublicAccess]
final readonly class OAuthAuthorizeController
{
    public function __construct(
        private McpOAuthConfig $config,
        private McpConsentAction $consent,
        private UrlGeneratorInterface $urls,
        #[Autowire(service: 'limiter.user_mcp_oauth')] private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/oauth/authorize', name: 'mcp_oauth_authorize', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $this->config->requireCanonicalRequest($request);
        $limit = $this->limiter->create('authorize:'.$request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return OAuthHttp::rateLimited($limit);
        }

        try {
            $id = $this->consent->begin(OAuthHttp::parameters($request));
        } catch (OAuthServerException $exception) {
            return OAuthHttp::error($exception);
        }

        return new RedirectResponse($this->urls->generate('mcp_user_consent', ['id' => $id]), 303, OAuthHttp::PRIVATE_HEADERS);
    }
}
