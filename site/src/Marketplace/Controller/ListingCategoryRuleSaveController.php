<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\SaveListingCategoryRuleAction;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ListingCategoryRuleSaveController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly SaveListingCategoryRuleAction $save,
    ) {
    }

    #[Route('/marketplace/listings/categories/rules', name: 'marketplace_listing_categories_rule_save', methods: ['POST'])]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('listing_categories', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        try {
            $changed = ($this->save)($companyId, $request->request->getString('sourceKey'), $request->request->getString('categoryId'));
            $this->addFlash('success', sprintf('Правило сохранено, категория назначена: %d листинг(ам).', $changed));
        } catch (ListingCategoryNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_listing_categories_manage');
    }
}
