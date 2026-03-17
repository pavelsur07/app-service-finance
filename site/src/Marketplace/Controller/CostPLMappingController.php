<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Finance\Facade\PLCategoryFacade;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceCostPLMappingRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/cost-pl-mapping')]
#[IsGranted('ROLE_USER')]
final class CostPLMappingController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService               $companyService,
        private readonly MarketplaceCostPLMappingRepository $mappingRepository,
        private readonly MarketplaceCostCategoryRepository  $costCategoryRepository,
        private readonly PLCategoryFacade                   $plCategoryFacade,
        private readonly EntityManagerInterface             $em,
    ) {
    }

    #[Route('', name: 'marketplace_cost_pl_mapping_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace') ?: null;

        $marketplaceType = $marketplace ? MarketplaceType::tryFrom($marketplace) : null;

        $allCategories = $this->costCategoryRepository->findByCompany($company);
        $costCategories = $marketplaceType !== null
            ? array_filter($allCategories, static fn($c) => $c->getMarketplace() === $marketplaceType)
            : $allCategories;

        $mappings = $this->mappingRepository->findByCompany($companyId);
        $mappingsIndexed = [];
        foreach ($mappings as $mapping) {
            $mappingsIndexed[$mapping->getCostCategory()->getId()] = $mapping;
        }

        $plCategories = $this->plCategoryFacade->getTreeByCompanyId($companyId);

        return $this->render('marketplace/cost_pl_mapping/index.html.twig', [
            'active_tab'             => 'cost_pl_mapping',
            'cost_categories'        => $costCategories,
            'mappings_indexed'       => $mappingsIndexed,
            'pl_categories'          => $plCategories,
            'available_marketplaces' => MarketplaceType::cases(),
            'selected_marketplace'   => $marketplace,
        ]);
    }

    #[Route('/bulk-save', name: 'marketplace_cost_pl_mapping_bulk_save', methods: ['POST'])]
    public function bulkSave(Request $request): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $marketplace = (string) $request->request->get('marketplace', '');

        $rows  = $request->request->all('mappings');
        $saved = 0;

        foreach ($rows as $costCategoryId => $data) {
            $plCategoryId = ($data['pl_category_id'] ?? '') ?: null;
            $includeInPl  = isset($data['include_in_pl']);
            $sortOrder    = (int) ($data['sort_order'] ?? 0);

            $costCategory = $this->costCategoryRepository->find($costCategoryId);
            if ($costCategory === null || (string) $costCategory->getCompany()->getId() !== $companyId) {
                continue;
            }

            if ($plCategoryId !== null
                && $this->plCategoryFacade->findByIdAndCompany($plCategoryId, $companyId) === null) {
                continue;
            }

            $mapping = $this->mappingRepository->findByCostCategory($companyId, $costCategoryId);

            if ($mapping === null) {
                $mapping = new MarketplaceCostPLMapping(
                    Uuid::uuid4()->toString(),
                    $companyId,
                    $costCategory,
                    $plCategoryId,
                    $includeInPl,
                );
                $this->em->persist($mapping);
            } else {
                $mapping->update($plCategoryId, $includeInPl, $sortOrder);
            }

            $saved++;
        }

        $this->em->flush();

        $this->addFlash('success', sprintf('Сохранено маппингов: %d', $saved));

        return $this->redirectToRoute('marketplace_cost_pl_mapping_index', [
            'marketplace' => $marketplace,
        ]);
    }
}
