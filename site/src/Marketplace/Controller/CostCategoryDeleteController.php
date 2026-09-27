<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\Action\DeleteCostCategoryAction;
use App\Marketplace\Exception\CostCategoryHasCostsException;
use App\Marketplace\Exception\CostCategoryNotFoundException;
use App\Marketplace\Exception\SystemCostCategoryDeletionException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/marketplace/cost-pl-mapping/{id}/delete-category',
    name: 'marketplace_cost_pl_mapping_delete_category',
    methods: ['POST'],
)]
#[IsGranted('ROLE_USER')]
final class CostCategoryDeleteController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'marketplace_cost_category_delete';

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly DeleteCostCategoryAction $deleteCategory,
    ) {
    }

    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(string $id, Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $marketplace = $request->request->getString('marketplace');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $category = ($this->deleteCategory)($companyId, $id);
            $this->addFlash('success', sprintf('Категория "%s" удалена', $category->getName()));
        } catch (CostCategoryNotFoundException) {
            throw $this->createNotFoundException();
        } catch (SystemCostCategoryDeletionException|CostCategoryHasCostsException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_cost_pl_mapping_index', ['marketplace' => $marketplace]);
    }
}
