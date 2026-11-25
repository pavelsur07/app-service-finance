<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\DTO\ListingCategoryDTO;
use App\Marketplace\Infrastructure\Query\ListingCategoryAssignmentQuery;
use App\Marketplace\Infrastructure\Query\ListingCategoryRuleQuery;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ListingCategoriesManageController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceListingCategoryRepository $categories,
        private readonly ListingCategoryAssignmentQuery $assignments,
        private readonly ListingCategoryRuleQuery $rules,
    ) {
    }

    #[Route('/marketplace/listings/categories', name: 'marketplace_listing_categories_manage', methods: ['GET'])]
    public function __invoke(): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $counts = $this->assignments->countsByCategory($companyId);

        return $this->render('marketplace/listings/categories.html.twig', [
            'categories' => array_map(
                static fn (ListingCategoryDTO $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'listingsCount' => $counts[$category->id] ?? 0,
                ],
                $this->categories->listForCompany($companyId),
            ),
            'unmatched' => $this->rules->unmatched($companyId),
            'rules' => $this->rules->rules($companyId),
            'active_tab' => 'listings_categories',
        ]);
    }
}
