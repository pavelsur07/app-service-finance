<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller;

use App\Marketplace\Enum\MarketplaceType;
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
    ) {}

    #[Route(
        '/marketplace-analytics/cost-mappings',
        name: 'marketplace_analytics_cost_mappings_index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $marketplace = $request->query->get('marketplace');
        $page = max(1, $request->query->getInt('page', 1));

        $marketplaceEnum = ($marketplace !== null && $marketplace !== '')
            ? MarketplaceType::tryFrom($marketplace)
            : null;

        if ($marketplaceEnum !== null) {
            ($this->ensureCostMappingsSeededAction)($company->getId(), $marketplaceEnum->value);
        } else {
            foreach (MarketplaceType::cases() as $type) {
                ($this->ensureCostMappingsSeededAction)($company->getId(), $type->value);
            }
        }

        $result = $this->repository->findPaginated(
            $company->getId(),
            $marketplaceEnum,
            $page,
            50,
        );

        $costTypes = array_column(UnitEconomyCostType::cases(), null, 'value');

        return $this->render('marketplace_analytics/cost_mappings/index.html.twig', [
            'mappings' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'filters' => ['marketplace' => $marketplace],
            'unitEconomyCostTypes' => $costTypes,
        ]);
    }
}
