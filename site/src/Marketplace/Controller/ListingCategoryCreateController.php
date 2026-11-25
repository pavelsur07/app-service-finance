<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\CreateListingCategoryAction;
use App\Marketplace\Entity\MarketplaceListingCategory;
use App\Marketplace\Exception\ListingCategoryNameConflictException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ListingCategoryCreateController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CreateListingCategoryAction $create,
    ) {
    }

    #[Route('/marketplace/listings/categories/create', name: 'marketplace_listing_categories_create', methods: ['POST'])]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('listing_categories', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $name = trim($request->request->getString('name'));

        if ('' === $name || \mb_strlen($name) > MarketplaceListingCategory::NAME_MAX_LENGTH) {
            $this->addFlash('danger', sprintf('Название категории — от 1 до %d символов.', MarketplaceListingCategory::NAME_MAX_LENGTH));
        } else {
            try {
                ($this->create)($companyId, $name);
                $this->addFlash('success', sprintf('Категория «%s» создана.', $name));
            } catch (ListingCategoryNameConflictException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('marketplace_listing_categories_manage');
    }
}
