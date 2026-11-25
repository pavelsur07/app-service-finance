<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\RenameListingCategoryAction;
use App\Marketplace\Entity\MarketplaceListingCategory;
use App\Marketplace\Exception\ListingCategoryNameConflictException;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ListingCategoryRenameController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly RenameListingCategoryAction $rename,
    ) {
    }

    #[Route(
        '/marketplace/listings/categories/{categoryId}/rename',
        name: 'marketplace_listing_categories_rename',
        requirements: ['categoryId' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(Request $request, string $categoryId): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('listing_categories', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $name = trim($request->request->getString('name'));

        if ('' === $name || \mb_strlen($name) > MarketplaceListingCategory::NAME_MAX_LENGTH) {
            $this->addFlash('danger', sprintf('Название категории — от 1 до %d символов.', MarketplaceListingCategory::NAME_MAX_LENGTH));

            return $this->redirectToRoute('marketplace_listing_categories_manage');
        }

        try {
            ($this->rename)($companyId, $categoryId, $name);
            $this->addFlash('success', sprintf('Категория переименована в «%s».', $name));
        } catch (ListingCategoryNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (ListingCategoryNameConflictException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_listing_categories_manage');
    }
}
