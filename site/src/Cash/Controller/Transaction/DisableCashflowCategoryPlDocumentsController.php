<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DisableCashflowCategoryPlDocumentsAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class DisableCashflowCategoryPlDocumentsController extends AbstractController
{
    #[Route('/cashflow-categories/disable-pl-documents', name: 'cashflow_category_disable_pl_documents', methods: ['POST'])]
    public function __invoke(
        Request $request,
        ActiveCompanyService $companyService,
        DisableCashflowCategoryPlDocumentsAction $disable,
    ): Response {
        $company = $companyService->getActiveCompany();
        if (!$this->isCsrfTokenValid('disable_cashflow_pl_documents', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен.');
        }

        $count = $disable((string) $company->getId());
        $this->addFlash('success', sprintf('Создание документов ОПиУ отключено у %d категорий.', $count));

        return $this->redirectToRoute('cashflow_category_index');
    }
}
