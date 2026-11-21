<?php

declare(strict_types=1);

namespace App\Mcp\Controller;

use App\Company\Entity\User;
use App\Company\Security\PublicAccess;
use App\Mcp\Infrastructure\OAuth\McpOAuthStore;
use App\Mcp\Infrastructure\OAuth\OAuthHttp;
use App\Mcp\Security\McpOAuthConfig;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
#[PublicAccess] // Uses the main login firewall and actor-scoped revocation.
final readonly class McpRevokeConnectionController
{
    public function __construct(
        private McpOAuthConfig $config,
        private McpOAuthStore $store,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/integrations/mcp/{id}/revoke', name: 'mcp_user_connection_revoke', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $actor, string $id): RedirectResponse
    {
        $this->config->requireCanonicalRequest($request);
        if (!$this->csrf->isTokenValid(new CsrfToken('mcp-revoke-'.$id, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('Не удалось проверить подтверждение.');
        }
        $this->store->revokeForActor($id, (string) $actor->getId());

        return new RedirectResponse($this->urls->generate('mcp_user_connections'), 303, OAuthHttp::PRIVATE_HEADERS);
    }
}
