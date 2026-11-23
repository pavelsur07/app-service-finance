<?php

declare(strict_types=1);

namespace App\Mcp\Controller;

use App\Company\Entity\User;
use App\Company\Security\PublicAccess;
use App\Mcp\Application\McpConsentAction;
use App\Mcp\Infrastructure\OAuth\OAuthHttp;
use App\Mcp\Security\McpOAuthConfig;
use App\Mcp\Security\McpScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;

#[AsController]
#[PublicAccess] // Uses the main login firewall and explicit ownership checks.
final readonly class McpConsentController
{
    public function __construct(
        private McpOAuthConfig $config,
        private McpConsentAction $consent,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
    ) {
    }

    #[Route('/integrations/mcp/authorize/{id}', name: 'mcp_user_consent', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $actor, string $id): Response
    {
        $this->config->requireCanonicalRequest($request);
        $tokenId = 'mcp-consent-'.$id;
        if ($request->isMethod('POST')) {
            $decision = $request->request->getString('decision');
            if (!\in_array($decision, ['approve', 'deny'], true)
                || !$this->csrf->isTokenValid(new CsrfToken($tokenId, $request->request->getString('_token')))) {
                throw new AccessDeniedHttpException('Не удалось проверить подтверждение.');
            }
            $companyId = 'approve' === $decision ? $request->request->getString('companyId') : null;

            return OAuthHttp::privateResponse($this->consent->finish($id, (string) $actor->getId(), $companyId));
        }

        $view = $this->consent->view($id, (string) $actor->getId());
        $view['scopeLabels'] = array_map(static fn (string $scope): string => McpScope::from($scope)->label(), $view['scopes']);
        $view['tokenId'] = $tokenId;

        return new Response($this->twig->render('mcp/consent.html.twig', $view), 200, OAuthHttp::PRIVATE_HEADERS);
    }
}
