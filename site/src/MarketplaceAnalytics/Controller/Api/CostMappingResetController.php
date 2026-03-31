<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

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

#[IsGranted('ROLE_USER')]
final class CostMappingResetController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceAnalyticsFacade $facade,
    ) {}

    #[Route(
        '/api/marketplace-analytics/cost-mappings/{id}/reset',
        name: 'marketplace_analytics_api_cost_mapping_reset',
        methods: ['PATCH'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $newType = UnitEconomyCostType::from($data['unit_economy_cost_type'] ?? '');

        try {
            $mapping = $this->facade->resetCostMapping($company->getId(), $id, $newType);
        } catch (\DomainException $e) {
            return $this->json(
                ['type' => 'DOMAIN_ERROR', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(CostMappingResponse::fromEntity($mapping)->toArray());
    }
}
