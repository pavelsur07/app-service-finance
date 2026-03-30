<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Response\CostMappingResponse;
use App\MarketplaceAnalytics\Facade\MarketplaceAnalyticsFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function __invoke(string $id): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        try {
            $mapping = $this->facade->resetCostMapping($company->getId(), $id);
        } catch (\DomainException $e) {
            return $this->json(
                ['type' => 'DOMAIN_ERROR', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(CostMappingResponse::fromEntity($mapping)->toArray());
    }
}
