<?php

declare(strict_types=1);

namespace App\Shared\Controller\Spa;

use App\Company\Security\PublicAccess;
use App\Shared\Api\Request\SpaLoginRequest;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Routing\Attribute\Route;

#[PublicAccess]
#[OA\Tag(name: 'Account session')]
final class LoginController
{
    #[Route('/api/account/v1/auth/login', name: 'spa_account_login', methods: ['POST'])]
    #[Route('/api/admin/v1/auth/login', name: 'spa_admin_login', methods: ['POST'])]
    #[OA\Post(summary: 'Войти существующей учётной записью', security: [], servers: [new OA\Server(url: 'https://account.vashfindir.ru')])]
    #[OA\Parameter(name: 'X-CSRF-Token', in: 'header', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SpaLoginRequest::class)))]
    #[OA\Response(response: 204, description: 'Вход выполнен; получить /session')]
    #[OA\Response(response: 400, description: 'Некорректный JSON')]
    #[OA\Response(response: 401, description: 'Неверный email или пароль')]
    #[OA\Response(response: 403, description: 'Недопустимый CSRF/Origin')]
    #[OA\Response(response: 415, description: 'Требуется JSON')]
    #[OA\Response(response: 422, description: 'Некорректные поля')]
    #[OA\Response(response: 429, description: 'Лимит попыток, Retry-After')]
    public function __invoke(): never
    {
        throw new \LogicException('SPA login must be intercepted by its authenticator.');
    }
}
