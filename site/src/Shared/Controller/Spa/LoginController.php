<?php

declare(strict_types=1);

namespace App\Shared\Controller\Spa;

use App\Company\Security\PublicAccess;
use Symfony\Component\Routing\Attribute\Route;

#[PublicAccess]
final class LoginController
{
    #[Route('/api/account/v1/auth/login', name: 'spa_account_login', methods: ['POST'])]
    #[Route('/api/admin/v1/auth/login', name: 'spa_admin_login', methods: ['POST'])]
    public function __invoke(): never
    {
        throw new \LogicException('SPA login must be intercepted by its authenticator.');
    }
}
