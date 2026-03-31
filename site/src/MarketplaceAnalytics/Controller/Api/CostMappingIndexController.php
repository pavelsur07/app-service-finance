<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Request\ListCostMappingsRequest;
use App\MarketplaceAnalytics\Api\Response\CostMappingResponse;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CostMappingIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly UnitEconomyCostMappingRepositoryInterface $repository,
    ) {}

    #[Route(
        '/api/marketplace-analytics/cost-mappings',
        name: 'marketplace_analytics_api_cost_mapping_index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $req = ListCostMappingsRequest::fromRequest($request);

        $result = $this->repository->findPaginated(
            $company->getId(),
            $req->marketplace,
            $req->page,
            $req->perPage,
        );

        $data = array_map(
            static fn($mapping) => CostMappingResponse::fromEntity($mapping)->toArray(),
            $result['items'],
        );

        $total = $result['total'];
        $pages = $req->perPage > 0 ? (int) ceil($total / $req->perPage) : 1;

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $req->page,
                'per_page' => $req->perPage,
                'pages' => $pages,
            ],
        ]);
    }
}
