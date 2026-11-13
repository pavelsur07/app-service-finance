<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Category\SaveCashflowCategoryAction;
use App\Cash\Form\Transaction\CashflowCategoryType;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Security\ModuleAccess;
use App\Finance\Facade\PLCategoryFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowCategoryNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly SaveCashflowCategoryAction $save,
        private readonly \App\Cash\Http\CashflowCategoryPage $page,
        private readonly CashflowCategoryRepository $repo,
        private readonly PLCategoryFacade $plCategoryFacade,
    ) {
    }

    #[Route('/new', name: 'cashflow_category_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $article = $this->save->createDraft($company);
        $parents = $this->page->regularParentChoices($this->repo->findTreeByCompany($company));
        $plCategories = $this->plCategoryFacade->findTreeEntitiesByCompanyId((string) $company->getId());
        $form = $this->createForm(CashflowCategoryType::class, $article, ['parents' => $parents, 'plCategories' => $plCategories, 'allow_flow_kind_edit' => true, 'protected_system_fields' => false]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->save)($article);

                return $this->redirectToRoute('cashflow_category_index');
            } catch (\DomainException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        return $this->render('cashflow_category/new.html.twig', ['form' => $form->createView()]);
    }
}
