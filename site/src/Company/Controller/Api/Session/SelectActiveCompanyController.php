<?php

declare(strict_types=1);

namespace App\Company\Controller\Api\Session;

use App\Company\Api\Request\SelectActiveCompanyRequest;
use App\Company\Api\Response\AccountSessionResponse;
use App\Company\Application\Query\AccountSessionQuery;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Shared\Security\Spa\SpaCsrf;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[OA\Tag(name: 'Account session')]
final class SelectActiveCompanyController extends AbstractController
{
    #[Route('/api/account/v1/active-company', name: 'spa_account_select_company', methods: ['POST'])]
    #[OA\Post(summary: 'Выбрать доступную компанию текущей сессии', servers: [new OA\Server(url: 'https://account.vashfindir.ru')])]
    #[OA\Parameter(name: 'X-CSRF-Token', in: 'header', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SelectActiveCompanyRequest::class)))]
    #[OA\Response(response: 200, description: 'Обновлённый контекст', content: new OA\JsonContent(ref: new Model(type: AccountSessionResponse::class)))]
    #[OA\Response(response: 401, description: 'Нет сессии')]
    #[OA\Response(response: 403, description: 'CSRF/Origin не прошли проверку')]
    #[OA\Response(response: 404, description: 'Компания недоступна')]
    #[OA\Response(response: 422, description: 'Некорректный идентификатор')]
    public function __invoke(Request $request, #[MapRequestPayload(acceptFormat: 'json')] SelectActiveCompanyRequest $input, CompanyFacade $companies, SpaCsrf $csrf, AccountSessionQuery $query): JsonResponse
    {
        $csrf->validate($request, 'company_select');
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$companies->userHasAccess($input->targetCompanyId, (string) $user->getId())) {
            throw $this->createNotFoundException();
        }
        $request->getSession()->set('active_company_id', $input->targetCompanyId);

        return $this->json($query()->toArray());
    }
}
