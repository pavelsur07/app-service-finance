<?php

declare(strict_types=1);

namespace App\Company\Controller\Api\Session;

use App\Company\Api\Response\AccountSessionResponse;
use App\Company\Application\Query\AccountSessionQuery;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[OA\Tag(name: 'Account session')]
final readonly class SessionController
{
    #[Route('/api/account/v1/session', name: 'spa_account_session', methods: ['GET'])]
    #[OA\Get(summary: 'Текущий пользователь, компания и права', servers: [new OA\Server(url: 'https://account.vashfindir.ru')])]
    #[OA\Response(response: 200, description: 'Контекст', content: new OA\JsonContent(ref: new Model(type: AccountSessionResponse::class)))]
    #[OA\Response(response: 401, description: 'Нет сессии')]
    public function __invoke(AccountSessionQuery $query): JsonResponse
    {
        return new JsonResponse($query()->toArray());
    }
}
