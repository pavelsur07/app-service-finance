<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Category\SaveCashflowCategoryAction;
use App\Cash\Form\Transaction\CashflowCategoryType;
use App\Cash\Infrastructure\Query\CashflowCategoryUsageQuery;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Security\ModuleAccess;
use App\Finance\Facade\PLCategoryFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowCategoryEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowCategoryRepository $repo,
        private readonly \App\Cash\Http\CashflowCategoryPage $page,
        private readonly PLCategoryFacade $plCategoryFacade,
        private readonly SaveCashflowCategoryAction $save,
        private readonly CashflowCategoryUsageQuery $usageQuery,
    ) {
    }

    #[Route('/{id}/edit', name: 'cashflow_category_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->companyService->getActiveCompany();
        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $article = $this->repo->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        $parents = $article->isSystem() ? [] : $this->page->regularParentChoices($this->repo->findTreeByCompany($company), $article);
        $plCategories = $this->plCategoryFacade->findTreeEntitiesByCompanyId((string) $company->getId());
        $form = $this->createForm(CashflowCategoryType::class, $article, ['parents' => $parents, 'plCategories' => $plCategories, 'allow_flow_kind_edit' => !$article->isSystem(), 'protected_system_fields' => $article->isSystem()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->save)($article);

                return $this->redirectToRoute('cashflow_category_index');
            } catch (\DomainException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        return $this->render('cashflow_category/edit.html.twig', ['form' => $form->createView(), 'article' => $article, 'usage' => ($this->usageQuery)((string) $company->getId(), $id)[$id] ?? null]);
    }
}
