<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Facade\MarketplaceAnalyticsFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class CostMappingDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceAnalyticsFacade $facade,
    ) {}

    #[Route(
        '/api/marketplace-analytics/cost-mappings/{id}',
        name: 'marketplace_analytics_api_cost_mapping_delete',
        methods: ['DELETE'],
    )]
    public function __invoke(string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        try {
            $this->facade->deleteCostMapping($company->getId(), $id);
        } catch (\DomainException $e) {
            return $this->json(
                ['type' => 'NOT_FOUND', 'message' => $e->getMessage()],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
