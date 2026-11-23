<?php

declare(strict_types=1);

namespace App\Mcp\Controller;

use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Security\PublicAccess;
use App\Mcp\Infrastructure\OAuth\McpOAuthStore;
use App\Mcp\Infrastructure\OAuth\OAuthHttp;
use App\Mcp\Security\McpOAuthConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;

#[AsController]
#[PublicAccess] // Uses the main login firewall and actor-scoped grants.
final readonly class McpConnectionsController
{
    public function __construct(
        private McpOAuthConfig $config,
        private McpOAuthStore $store,
        private CompanyFacade $companies,
        private Environment $twig,
    ) {
    }

    #[Route('/integrations/mcp', name: 'mcp_user_connections', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $actor): Response
    {
        $this->config->requireCanonicalRequest($request);
        $actorId = (string) $actor->getId();
        $companies = array_column($this->companies->listAccessibleCompaniesForUser($actorId), 'name', 'id');

        return new Response($this->twig->render('mcp/connections.html.twig', [
            'connections' => $this->store->connectionsForActor($actorId),
            'companies' => $companies,
        ]), 200, OAuthHttp::PRIVATE_HEADERS);
    }
}
