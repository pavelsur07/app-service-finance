<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Service\AutoRule\CashTransactionAutoRuleTargetValidator;
use App\Cash\Form\Transaction\CashTransactionAutoRuleType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash-transaction-auto-rules')]
final class CashAutoRuleEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionAutoRuleRepository $repo,
        private readonly CashflowCategoryRepository $categoryRepo,
        private readonly MoneyAccountRepository $moneyAccountRepo,
        private readonly CompanyFacade $companyFacade,
        private readonly \App\Cash\Http\CashAutoRulePage $page,
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        private readonly CashTransactionAutoRuleTargetValidator $targetValidator,
        private readonly AuditContextProvider $auditContextProvider,
    ) {
    }

    #[Route('/{id}/edit', name: 'cash_transaction_auto_rule_edit', methods: ['GET', 'POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $companyId = (string) $company->getId();
        $original = $this->repo->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (!$original) {
            throw $this->createNotFoundException();
        }
        $rule = $original->editableDraft();
        $actorId = $this->auditContextProvider->getActorUserId();
        $categories = $this->categoryRepo->findTreeByCompany($company);
        $moneyAccounts = $this->moneyAccountRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $projectDirections = $this->companyFacade->listProjectDirectionEntities((string) $company->getId(), treeOrder: false);
        $currentProjectDirectionId = $rule->getProjectDirection()?->getId();
        $currentResponsibilityCenterId = $rule->getResponsibilityCenterId();
        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, ['categories' => $categories, 'company_id' => $companyId, 'moneyAccounts' => $moneyAccounts, 'projectDirections' => $projectDirections, 'responsibilityCenterChoices' => $this->page->getResponsibilityCenterChoices($this->responsibilityCenterFacade, $companyId, $currentResponsibilityCenterId)]);
        $form->handleRequest($request);
        $this->page->validatePairTarget($form, $this->targetValidator, $companyId, $currentProjectDirectionId, $currentResponsibilityCenterId, $rule);
        $result = $this->page->processForm($form, $request, $rule, $original, $actorId);
        if ($result['saved']) {
            return $this->redirectToRoute('cash_transaction_auto_rule_index');
        }

        return $this->render('cash_transaction_auto_rule/edit.html.twig', $result + ['draftId' => $rule->getId(), 'form' => $form->createView(), 'item' => $rule]);
    }
}
