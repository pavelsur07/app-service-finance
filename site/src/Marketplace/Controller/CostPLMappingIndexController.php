<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Finance\Facade\PLCategoryFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\CostPLMappingListQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/cost-pl-mapping', name: 'marketplace_cost_pl_mapping_index', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class CostPLMappingIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CostPLMappingListQuery $listQuery,
        private readonly PLCategoryFacade $plCategoryFacade,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $marketplace = MarketplaceType::tryFrom($request->query->getString('marketplace'));

        $plCategories = $this->plCategoryFacade->getTreeByCompanyId($companyId);
        $plCategoryNames = [];
        foreach ($plCategories as $plCategory) {
            $plCategoryNames[$plCategory->id] = $plCategory->name;
        }

        return $this->render('marketplace/cost_pl_mapping/index.html.twig', [
            'active_tab' => 'cost_pl_mapping',
            'rows' => $this->listQuery->fetch($companyId, $marketplace),
            'pl_categories' => $plCategories,
            'pl_category_names' => $plCategoryNames,
            'available_marketplaces' => MarketplaceType::cases(),
            'selected_marketplace' => $marketplace?->value,
        ]);
    }
}
