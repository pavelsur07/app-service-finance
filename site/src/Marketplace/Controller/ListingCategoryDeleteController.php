<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\DeleteListingCategoryAction;
use App\Marketplace\Exception\ListingCategoryInUseException;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ListingCategoryDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly DeleteListingCategoryAction $delete,
    ) {
    }

    #[Route(
        '/marketplace/listings/categories/{categoryId}/delete',
        name: 'marketplace_listing_categories_delete',
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

        try {
            ($this->delete)($companyId, $categoryId);
            $this->addFlash('success', 'Категория удалена.');
        } catch (ListingCategoryNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (ListingCategoryInUseException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_listing_categories_manage');
    }
}
