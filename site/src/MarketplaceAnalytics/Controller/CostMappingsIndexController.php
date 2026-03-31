<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Application\EnsureCostMappingsSeededAction;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class CostMappingsIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly UnitEconomyCostMappingRepositoryInterface $repository,
        private readonly EnsureCostMappingsSeededAction $ensureCostMappingsSeededAction,
        private readonly MarketplaceFacade $marketplaceFacade,
    ) {}

    #[Route(
        '/marketplace-analytics/cost-mappings',
        name: 'marketplace_analytics_cost_mappings_index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $marketplaceEnum = MarketplaceType::tryFrom($request->query->get('marketplace') ?? '')
            ?? MarketplaceType::WILDBERRIES;

        $selectedMarketplace = $marketplaceEnum->value;

        ($this->ensureCostMappingsSeededAction)($company->getId(), $marketplaceEnum->value);

        $page = max(1, $request->query->getInt('page', 1));

        $result = $this->repository->findPaginated(
            $company->getId(),
            $selectedMarketplace,
            $page,
            50,
        );

        $categories = $this->marketplaceFacade->getCostCategoriesForCompany(
            $company->getId(),
            $selectedMarketplace,
        );

        return $this->render('marketplace_analytics/cost_mappings/index.html.twig', [
            'mappings'               => $result['items'],
            'total'                  => $result['total'],
            'page'                   => $page,
            'available_marketplaces' => MarketplaceType::cases(),
            'selected_marketplace'   => $selectedMarketplace,
            'categories'             => $categories,
            'costTypes'              => UnitEconomyCostType::cases(),
            'filters'                => ['marketplace' => $selectedMarketplace],
        ]);
    }
}
