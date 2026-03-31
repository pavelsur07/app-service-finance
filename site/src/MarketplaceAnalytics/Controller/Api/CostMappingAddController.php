<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Api\Response\CostMappingResponse;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Facade\MarketplaceAnalyticsFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class CostMappingAddController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceAnalyticsFacade $facade,
    ) {}

    #[Route(
        '/api/marketplace-analytics/cost-mappings',
        name: 'marketplace_analytics_api_cost_mapping_add',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $body = json_decode($request->getContent(), true) ?? [];

        $marketplace         = $body['marketplace'] ?? '';
        $costCategoryId      = $body['costCategoryId'] ?? '';
        $costCategoryName    = $body['costCategoryName'] ?? '';
        $unitEconomyCostType = $body['unitEconomyCostType'] ?? '';

        if ($marketplace === '' || $costCategoryId === '' || $costCategoryName === '' || $unitEconomyCostType === '') {
            return $this->json(
                ['type' => 'VALIDATION_ERROR', 'message' => 'Все поля обязательны'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (MarketplaceType::tryFrom($marketplace) === null) {
            return $this->json(
                ['type' => 'VALIDATION_ERROR', 'message' => 'Неверное значение маркетплейса'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $type = UnitEconomyCostType::tryFrom($unitEconomyCostType);
        if ($type === null) {
            return $this->json(
                ['type' => 'VALIDATION_ERROR', 'message' => 'Неверное значение статьи юнит-экономики'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $mapping = $this->facade->addCostMapping(
                $company->getId(),
                $marketplace,
                $costCategoryId,
                $costCategoryName,
                $type,
            );
        } catch (\DomainException $e) {
            return $this->json(
                ['type' => 'DOMAIN_ERROR', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(CostMappingResponse::fromEntity($mapping)->toArray(), Response::HTTP_CREATED);
    }
}
