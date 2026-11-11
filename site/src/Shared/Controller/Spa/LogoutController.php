<?php

declare(strict_types=1);

namespace App\Shared\Controller\Spa;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LogoutController
{
    #[Route('/api/account/v1/auth/logout', name: 'spa_account_logout', methods: ['POST'])]
    #[Route('/api/admin/v1/auth/logout', name: 'spa_admin_logout', methods: ['POST'])]
    public function __invoke(): never
    {
        throw new \LogicException('SPA logout must be intercepted by Symfony.');
    }
}
