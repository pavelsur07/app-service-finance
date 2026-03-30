<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Response\CostMappingResponse;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CostMappingShowController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly UnitEconomyCostMappingRepositoryInterface $repository,
    ) {}

    #[Route(
        '/api/marketplace-analytics/cost-mappings/{id}',
        name: 'marketplace_analytics_api_cost_mapping_show',
        methods: ['GET'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $mapping = $this->repository->findByIdAndCompany($id, $company->getId());

        if ($mapping === null) {
            return $this->json(
                ['type' => 'NOT_FOUND', 'message' => 'Маппинг не найден'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json(CostMappingResponse::fromEntity($mapping)->toArray());
    }
}
