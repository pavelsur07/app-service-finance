<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Request\CreateMarketplaceAnalyticsRequest;
use App\MarketplaceAnalytics\Application\CreateMarketplaceAnalyticsAction;
use App\Shared\Service\ActiveCompanyService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_OWNER')]
#[OA\Tag(name: 'Marketplace Analytics')]
final class MarketplaceAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CreateMarketplaceAnalyticsAction $createAction,
    ) {}

    #[OA\Post(
        summary: 'Создать маркетплейс-аналитику',
        description: 'Создаёт новый объект marketplace analytics для активной компании. Компания определяется по сессии через ActiveCompanyService.',
        tags: ['Marketplace Analytics']
    )]
    #[OA\RequestBody(
        required: true,
        content: new Model(type: CreateMarketplaceAnalyticsRequest::class)
    )]
    #[OA\Response(
        response: 201,
        description: 'Создано',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Не авторизован')]
    #[OA\Response(response: 403, description: 'Недостаточно прав (требуется ROLE_COMPANY_OWNER)')]
    #[OA\Response(
        response: 404,
        description: 'У пользователя нет активной компании (ActiveCompanyService::getActiveCompany() бросает NotFoundHttpException)'
    )]
    #[OA\Response(
        response: 422,
        description: 'Ошибка валидации входных данных (legacy-формат, не RFC 7807)'
    )]
    #[Route('/api/marketplaceanalytics', name: 'api_marketplaceanalytics_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] CreateMarketplaceAnalyticsRequest $request,
    ): JsonResponse {
        $company = $this->activeCompanyService->getActiveCompany();

        ($this->createAction)($company->getId(), $request);

        return $this->json(['status' => 'success'], 201);
    }
}
