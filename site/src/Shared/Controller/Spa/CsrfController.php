<?php

declare(strict_types=1);

namespace App\Shared\Controller\Spa;

use App\Company\Security\PublicAccess;
use App\Shared\Api\Response\SpaCsrfResponse;
use App\Shared\Security\Spa\SpaContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[PublicAccess]
final readonly class CsrfController
{
    #[Route('/api/account/v1/auth/csrf', name: 'spa_account_csrf', methods: ['GET'])]
    #[Route('/api/admin/v1/auth/csrf', name: 'spa_admin_csrf', methods: ['GET'])]
    public function __invoke(Request $request, SpaContext $context, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $tokens = [];
        $purposes = 'account' === $context->realm($request) ? ['login', 'logout', 'company_select'] : ['login', 'logout'];
        foreach ($purposes as $purpose) {
            $tokens[$purpose] = $csrf->getToken($context->tokenId($request, $purpose))->getValue();
        }

        return new JsonResponse((new SpaCsrfResponse($tokens))->toArray());
    }
}
