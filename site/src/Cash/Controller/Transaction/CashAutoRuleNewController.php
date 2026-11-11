<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\Service\CashTransactionAutoRulePrefiller;
use App\Cash\Application\Service\CashTransactionAutoRuleTargetValidator;
use App\Cash\Form\Transaction\CashTransactionAutoRuleType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash-transaction-auto-rules')]
final class CashAutoRuleNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowCategoryRepository $categoryRepo,
        private readonly MoneyAccountRepository $moneyAccountRepo,
        private readonly CompanyFacade $companyFacade,
        private readonly SaveCashTransactionAutoRuleAction $save,
        private readonly AuditContextProvider $auditContextProvider,
        private readonly CashTransactionRepository $txRepo,
        private readonly CashTransactionAutoRulePrefiller $prefiller,
        private readonly \App\Cash\Http\CashAutoRulePage $page,
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        private readonly CashTransactionAutoRuleTargetValidator $targetValidator,
    ) {
    }

    #[Route('/new', name: 'cash_transaction_auto_rule_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $companyId = (string) $company->getId();
        $categories = array_values($this->categoryRepo->findTreeByCompany($company));
        $moneyAccounts = $this->moneyAccountRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $projectDirections = $this->companyFacade->listProjectDirectionEntities($companyId, treeOrder: false);
        $rule = $this->save->createDraft($company, $this->auditContextProvider->getActorUserId());
        // Операция-источник нужна и при повторном рендере после невалидного POST:
        // именно тогда пользователь правит условие и смотрит на назначение платежа.
        $sourceTransaction = null;
        $fromTransactionId = trim((string) $request->query->get('fromTransaction', ''));
        if ('' !== $fromTransactionId) {
            $sourceTransaction = Uuid::isValid($fromTransactionId) ? $this->txRepo->findOneByIdAndCompanyId($fromTransactionId, $companyId) : null;
            if (null === $sourceTransaction) {
                throw $this->createNotFoundException('Операция не найдена.');
            }
            if ($request->isMethod('GET')) {
                $this->prefiller->prefill($rule, $sourceTransaction, $categories);
            }
        }
        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, ['categories' => $categories, 'company_id' => $companyId, 'moneyAccounts' => $moneyAccounts, 'projectDirections' => $projectDirections, 'responsibilityCenterChoices' => $this->page->getResponsibilityCenterChoices($this->responsibilityCenterFacade, $companyId)]);
        $form->handleRequest($request);
        $this->page->validatePairTarget($form, $this->targetValidator, $companyId, null, null, $rule);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->save)($rule, $this->auditContextProvider->getActorUserId());

                return $this->redirectToRoute('cash_transaction_auto_rule_index');
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('cash_transaction_auto_rule/new.html.twig', ['form' => $form->createView(), 'sourceTransaction' => $sourceTransaction]);
    }
}
