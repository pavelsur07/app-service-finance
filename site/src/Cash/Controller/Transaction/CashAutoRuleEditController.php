<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\Service\CashTransactionAutoRuleTargetValidator;
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
use Symfony\Component\Form\FormError;
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
        private readonly SaveCashTransactionAutoRuleAction $save,
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
        $rule = $this->repo->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (!$rule) {
            throw $this->createNotFoundException();
        }
        $categories = $this->categoryRepo->findTreeByCompany($company);
        $moneyAccounts = $this->moneyAccountRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $projectDirections = $this->companyFacade->listProjectDirectionEntities((string) $company->getId(), treeOrder: false);
        $currentProjectDirectionId = $rule->getProjectDirection()?->getId();
        $currentResponsibilityCenterId = $rule->getResponsibilityCenterId();
        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, ['categories' => $categories, 'company_id' => $companyId, 'moneyAccounts' => $moneyAccounts, 'projectDirections' => $projectDirections, 'responsibilityCenterChoices' => $this->page->getResponsibilityCenterChoices($this->responsibilityCenterFacade, $companyId, $currentResponsibilityCenterId)]);
        $form->handleRequest($request);
        $this->page->validatePairTarget($form, $this->targetValidator, $companyId, $currentProjectDirectionId, $currentResponsibilityCenterId, $rule);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->save)($rule, $this->auditContextProvider->getActorUserId(), $currentProjectDirectionId, $currentResponsibilityCenterId);

                return $this->redirectToRoute('cash_transaction_auto_rule_index');
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('cash_transaction_auto_rule/edit.html.twig', ['form' => $form->createView(), 'item' => $rule]);
    }
}
