<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Category\DeleteCashflowCategoryAction;
use App\Cash\Exception\CashflowCategoryInUseException;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowCategoryDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowCategoryRepository $repo,
        private readonly DeleteCashflowCategoryAction $deleteCategory,
    ) {
    }

    #[Route('/{id}/delete', name: 'cashflow_category_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->companyService->getActiveCompany();
        $article = $this->repo->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        if ($this->isCsrfTokenValid('delete'.$article->getId(), (string) $request->request->get('_token'))) {
            try {
                ($this->deleteCategory)($article);
            } catch (CashflowCategoryInUseException|\DomainException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('cashflow_category_index');
    }
}
