<?php

declare(strict_types=1);

namespace App\Shared\Controller\Spa;

use OpenApi\Attributes as OA;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[OA\Tag(name: 'Account session')]
final class LogoutController
{
    #[Route('/api/account/v1/auth/logout', name: 'spa_account_logout', methods: ['POST'])]
    #[Route('/api/admin/v1/auth/logout', name: 'spa_admin_logout', methods: ['POST'])]
    #[OA\Post(summary: 'Завершить сессию только текущего хоста', servers: [new OA\Server(url: 'https://account.vashfindir.ru')])]
    #[OA\Parameter(name: 'X-CSRF-Token', in: 'header', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 204, description: 'Выход выполнен')]
    #[OA\Response(response: 401, description: 'Сессия уже завершена')]
    #[OA\Response(response: 403, description: 'Недопустимый CSRF/Origin')]
    public function __invoke(): never
    {
        throw new \LogicException('SPA logout must be intercepted by Symfony.');
    }
}
