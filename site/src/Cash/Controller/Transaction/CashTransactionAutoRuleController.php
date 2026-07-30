<?php

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashTransactionAutoRulePreviewFilter;
use App\Cash\Application\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\CashTransactionAutoRulePrefiller;
use App\Cash\Application\Service\CashTransactionAutoRuleTargetValidator;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Form\Transaction\CashTransactionAutoRuleType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\ProjectDirectionRepository;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash-transaction-auto-rules')]
class CashTransactionAutoRuleController extends AbstractController
{
    #[Route('/', name: 'cash_transaction_auto_rule_index', methods: ['GET'])]
    public function index(
        Request $request,
        CashTransactionAutoRuleRepository $repo,
        ActiveCompanyService $companyService,
        CashflowCategoryRepository $categoryRepo,
        FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ): Response {
        $company = $companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $categories = $categoryRepo->findTreeByCompany($company);

        $actionValue = $request->query->get('action');
        $operationTypeValue = $request->query->get('operationType');
        $categoryValue = $request->query->get('category');

        $actionFilter = $actionValue ? CashTransactionAutoRuleAction::tryFrom($actionValue) : null;
        $operationTypeFilter = $operationTypeValue ? CashTransactionAutoRuleOperationType::tryFrom($operationTypeValue) : null;

        $categoryFilter = null;
        if ($categoryValue) {
            foreach ($categories as $category) {
                if ($category->getId() === $categoryValue) {
                    $categoryFilter = $category;
                    break;
                }
            }
        }

        $items = $repo->findByCompany($company, $actionFilter, $operationTypeFilter, $categoryFilter);

        $actionOptions = array_map(
            static fn (CashTransactionAutoRuleAction $action) => [
                'value' => $action->value,
                'label' => match ($action) {
                    CashTransactionAutoRuleAction::FILL => 'Безопасно заполнить пустые поля',
                    CashTransactionAutoRuleAction::UPDATE => 'Безопасно заполнить пустые поля (legacy UPDATE)',
                },
            ],
            CashTransactionAutoRuleAction::cases(),
        );

        $operationOptions = array_map(
            static fn (CashTransactionAutoRuleOperationType $type) => [
                'value' => $type->value,
                'label' => match ($type) {
                    CashTransactionAutoRuleOperationType::OUTFLOW => 'Отток',
                    CashTransactionAutoRuleOperationType::INFLOW => 'Приток',
                    CashTransactionAutoRuleOperationType::ANY => 'Любое',
                },
            ],
            [
                CashTransactionAutoRuleOperationType::OUTFLOW,
                CashTransactionAutoRuleOperationType::INFLOW,
                CashTransactionAutoRuleOperationType::ANY,
            ],
        );

        $categoryOptions = array_map(
            static fn (CashflowCategory $category) => [
                'id' => $category->getId(),
                'label' => trim(str_repeat('—', $category->getLevel() - 1).' '.$category->getName()),
            ],
            $categories,
        );

        return $this->render('cash_transaction_auto_rule/index.html.twig', [
            'items' => $items,
            'categories' => $categories,
            'actionOptions' => $actionOptions,
            'operationOptions' => $operationOptions,
            'categoryOptions' => $categoryOptions,
            'responsibilityCenterLabels' => array_flip($this->getResponsibilityCenterChoices(
                $responsibilityCenterFacade,
                $companyId,
            )),
            'filters' => [
                'category' => $categoryValue,
                'action' => $actionValue,
                'operationType' => $operationTypeValue,
            ],
        ]);
    }

    #[Route('/new', name: 'cash_transaction_auto_rule_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        SaveCashTransactionAutoRuleAction $save,
        ActiveCompanyService $companyService,
        CashflowCategoryRepository $categoryRepo,
        MoneyAccountRepository $moneyAccountRepo,
        ProjectDirectionRepository $projectDirectionRepo,
        FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        CashTransactionAutoRuleTargetValidator $targetValidator,
        AuditContextProvider $auditContextProvider,
        CashTransactionRepository $txRepo,
        CashTransactionAutoRulePrefiller $prefiller,
    ): Response {
        $company = $companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $categories = $categoryRepo->findTreeByCompany($company);
        $moneyAccounts = $moneyAccountRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $projectDirections = $projectDirectionRepo->findBy(['company' => $company], ['name' => 'ASC']);

        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            '',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            createdByUserId: $auditContextProvider->getActorUserId(),
        );

        // Операция-источник нужна и при повторном рендере после невалидного POST:
        // именно тогда пользователь правит условие и смотрит на назначение платежа.
        $sourceTransaction = null;
        $fromTransactionId = trim((string) $request->query->get('fromTransaction', ''));
        if ('' !== $fromTransactionId) {
            $sourceTransaction = Uuid::isValid($fromTransactionId)
                ? $txRepo->findOneByIdAndCompanyId($fromTransactionId, $companyId)
                : null;

            if (null === $sourceTransaction) {
                throw $this->createNotFoundException('Операция не найдена.');
            }

            if ($request->isMethod('GET')) {
                $prefiller->prefill($rule, $sourceTransaction, $categories);
            }
        }

        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, [
            'categories' => $categories,
            'company_id' => $companyId,
            'moneyAccounts' => $moneyAccounts,
            'projectDirections' => $projectDirections,
            'responsibilityCenterChoices' => $this->getResponsibilityCenterChoices(
                $responsibilityCenterFacade,
                $companyId,
            ),
        ]);
        $form->handleRequest($request);
        $this->validatePairTarget($form, $targetValidator, $companyId, null, null, $rule);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $save($rule, $auditContextProvider->getActorUserId());

                return $this->redirectToRoute('cash_transaction_auto_rule_index');
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('cash_transaction_auto_rule/new.html.twig', [
            'form' => $form->createView(),
            'sourceTransaction' => $sourceTransaction,
        ]);
    }

    #[Route('/{id}/edit', name: 'cash_transaction_auto_rule_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $id,
        Request $request,
        CashTransactionAutoRuleRepository $repo,
        SaveCashTransactionAutoRuleAction $save,
        ActiveCompanyService $companyService,
        CashflowCategoryRepository $categoryRepo,
        MoneyAccountRepository $moneyAccountRepo,
        ProjectDirectionRepository $projectDirectionRepo,
        FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        CashTransactionAutoRuleTargetValidator $targetValidator,
        AuditContextProvider $auditContextProvider,
    ): Response {
        $company = $companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $rule = $repo->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (!$rule) {
            throw $this->createNotFoundException();
        }

        $categories = $categoryRepo->findTreeByCompany($company);
        $moneyAccounts = $moneyAccountRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $projectDirections = $projectDirectionRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $currentProjectDirectionId = $rule->getProjectDirection()?->getId();
        $currentResponsibilityCenterId = $rule->getResponsibilityCenterId();
        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, [
            'categories' => $categories,
            'company_id' => $companyId,
            'moneyAccounts' => $moneyAccounts,
            'projectDirections' => $projectDirections,
            'responsibilityCenterChoices' => $this->getResponsibilityCenterChoices(
                $responsibilityCenterFacade,
                $companyId,
                $currentResponsibilityCenterId,
            ),
        ]);
        $form->handleRequest($request);
        $this->validatePairTarget(
            $form,
            $targetValidator,
            $companyId,
            $currentProjectDirectionId,
            $currentResponsibilityCenterId,
            $rule,
        );

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $save(
                    $rule,
                    $auditContextProvider->getActorUserId(),
                    $currentProjectDirectionId,
                    $currentResponsibilityCenterId,
                );

                return $this->redirectToRoute('cash_transaction_auto_rule_index');
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('cash_transaction_auto_rule/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $rule,
        ]);
    }

    #[Route('/{id}/check', name: 'cash_transaction_auto_rule_check', methods: ['GET'])]
    public function check(
        string $id,
        Request $request,
        ActiveCompanyService $companyService,
        CashTransactionAutoRuleRepository $ruleRepo,
        CashTransactionRepository $txRepo,
        CashTransactionAutoRuleService $autoRuleService,
    ): Response {
        $company = $companyService->getActiveCompany();
        $rule = $ruleRepo->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (!$rule) {
            throw $this->createNotFoundException();
        }

        $today = new \DateTimeImmutable('today');
        $dateFrom = (string) $request->query->get('dateFrom', $today->modify('-6 months')->format('Y-m-d'));
        $dateTo = (string) $request->query->get('dateTo', $today->format('Y-m-d'));
        $limit = (string) $request->query->get('limit', '200');

        try {
            $filter = CashTransactionAutoRulePreviewFilter::fromStrings($dateFrom, $dateTo, $limit);
        } catch (\InvalidArgumentException $exception) {
            return $this->render('cash_transaction_auto_rule/check.html.twig', [
                'rule' => $rule,
                'previewRows' => [],
                'limit' => 200,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'filterError' => $exception->getMessage(),
                'preview' => null,
            ], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        $preview = $autoRuleService->previewRule(
            $rule,
            $txRepo->iterateForAutoRulePreview($company, $filter->dateFrom, $filter->dateTo),
            $filter->limit,
        );

        return $this->render('cash_transaction_auto_rule/check.html.twig', [
            'rule' => $rule,
            'previewRows' => $preview->rows,
            'preview' => $preview,
            'limit' => $filter->limit,
            'dateFrom' => $filter->dateFrom->format('Y-m-d'),
            'dateTo' => $filter->dateTo->format('Y-m-d'),
            'filterError' => null,
        ]);
    }

    #[Route('/match/{transactionId}', name: 'cash_transaction_auto_rule_match_one', methods: ['GET'])]
    public function matchOne(
        string $transactionId,
        ActiveCompanyService $companyService,
        CashTransactionRepository $txRepo,
        CashTransactionAutoRuleService $autoRuleService,
        FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ): Response {
        $company = $companyService->getActiveCompany();

        /** @var CashTransaction|null $t */
        $t = $txRepo->findOneByIdAndCompanyId($transactionId, (string) $company->getId());
        if (!$t) {
            throw $this->createNotFoundException();
        }

        $skipReason = $autoRuleService->getSkipReason($t);
        $match = null === $skipReason ? $autoRuleService->match($t) : null;
        $hasPairConflict = null !== $match && (isset($match->conflicts['projectDirection'])
            || isset($match->conflicts['responsibilityCenterId']));
        $plan = null !== $match && ($match->hasWinners() || $hasPairConflict)
            ? $autoRuleService->createApplicationPlan($match, $t)
            : null;
        $responsibilityCenterLabels = [];
        foreach ($responsibilityCenterFacade->getActiveChoices((string) $company->getId()) as $center) {
            $responsibilityCenterLabels[$center->id] = $center->name;
        }

        return $this->render('cash_transaction_auto_rule/_auto_rule_modal_body.html.twig', [
            'transaction' => $t,
            'rule' => $match?->rule,
            'conflictingRules' => $match?->conflictingRules ?? [],
            'conflicts' => $match?->conflicts ?? [],
            'skipReason' => $skipReason,
            'plan' => $plan,
            'responsibilityCenterLabels' => $responsibilityCenterLabels,
        ]);
    }

    #[Route('/apply/{transactionId}', name: 'cash_transaction_auto_rule_apply_one', methods: ['POST'])]
    public function applyOne(
        string $transactionId,
        Request $request,
        ActiveCompanyService $companyService,
        CashTransactionRepository $txRepo,
        CashTransactionAutoRuleService $autoRuleService,
        CsrfTokenManagerInterface $csrfTokenManager,
        EntityManagerInterface $entityManager,
        AutoRuleDispatchGuard $dispatchGuard,
        AuditContextProvider $auditContextProvider,
    ): Response {
        $csrfToken = new CsrfToken(
            'apply-auto-rule'.$transactionId,
            (string) $request->request->get('_token', ''),
        );
        if (!$csrfTokenManager->isTokenValid($csrfToken)) {
            return new JsonResponse([
                'ok' => false,
                'changed' => false,
                'reason' => 'invalid_csrf_token',
                'message' => 'Недействительный CSRF-токен.',
            ], Response::HTTP_FORBIDDEN);
        }

        $company = $companyService->getActiveCompany();

        /** @var CashTransaction|null $t */
        $t = $txRepo->findOneByIdAndCompanyId($transactionId, (string) $company->getId());
        if (!$t) {
            throw $this->createNotFoundException();
        }

        $skipReason = $autoRuleService->getSkipReason($t);
        if (null !== $skipReason) {
            return new JsonResponse([
                'ok' => false,
                'changed' => false,
                'reason' => $skipReason->value,
                'message' => $skipReason->label(),
            ], 200);
        }

        $match = $autoRuleService->match($t);
        if (!$match->hasWinners() && $match->hasConflict()) {
            return new JsonResponse([
                'ok' => false,
                'changed' => false,
                'reason' => 'conflict',
                'message' => 'Все найденные поля конфликтуют и не будут изменены',
            ], 200);
        }

        $requestedRuleId = (string) $request->request->get('ruleId', '');
        $rule = $match->rule;
        if (!$rule || ('' !== $requestedRuleId && !$match->hasWinnerId($requestedRuleId))) {
            return new JsonResponse(['ok' => false, 'message' => 'Подходящее правило не найдено'], 200);
        }

        $applicationPlan = $autoRuleService->applyRule($rule, $t, $match);
        $changed = $applicationPlan?->hasChanges() ?? false;
        if ($changed) {
            $entityManager->persist(new AuditLog(
                (string) $t->getCompany()->getId(),
                CashTransaction::class,
                (string) $t->getId(),
                AuditLogAction::UPDATE,
                $applicationPlan->auditDiff(Uuid::uuid7()->toString()),
                $auditContextProvider->getActorUserId(),
            ));
            $dispatchGuard->suppress(
                static fn () => $entityManager->flush(),
                $applicationPlan,
            );
        }

        return new JsonResponse([
            'ok' => true,
            'changed' => $changed,
            'ruleName' => $rule->getName(),
            'action' => $rule->getAction()->value,
        ], 200);
    }

    #[Route('/{id}/delete', name: 'cash_transaction_auto_rule_delete', methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        CashTransactionAutoRuleRepository $repo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
        AuditContextProvider $auditContextProvider,
    ): Response {
        $company = $companyService->getActiveCompany();
        $rule = $repo->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (!$rule) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('disable'.$rule->getId(), $request->request->get('_token'))
            && $rule->disable($auditContextProvider->getActorUserId())) {
            $em->flush();
        }

        return $this->redirectToRoute('cash_transaction_auto_rule_index');
    }

    /** @return array<string, string> */
    private function getResponsibilityCenterChoices(
        FinancialResponsibilityCenterFacade $facade,
        string $companyId,
        ?string $currentId = null,
    ): array {
        $centers = $facade->getActiveChoices($companyId);
        $activeIds = array_map(
            static fn (FinancialResponsibilityCenterDTO $center): string => $center->id,
            $centers,
        );
        if (null !== $currentId && !in_array($currentId, $activeIds, true)) {
            $current = $facade->findByIdAndCompany($currentId, $companyId);
            if (null !== $current) {
                $centers[] = $current;
            }
        }

        $choices = [];
        foreach ($centers as $center) {
            $choices[sprintf(
                '%s [%s]%s',
                $center->name,
                $center->code,
                $center->isActive() ? '' : ' — архив, только сохранение',
            )] = $center->id;
        }

        return $choices;
    }

    private function validatePairTarget(
        FormInterface $form,
        CashTransactionAutoRuleTargetValidator $validator,
        string $companyId,
        ?string $currentProjectDirectionId,
        ?string $currentResponsibilityCenterId,
        CashTransactionAutoRule $rule,
    ): void {
        if (!$form->isSubmitted() || !$form->isSynchronized()) {
            return;
        }

        try {
            $validator->assertValidChange(
                $companyId,
                $currentProjectDirectionId,
                $currentResponsibilityCenterId,
                $rule->getProjectDirection()?->getId(),
                $rule->getResponsibilityCenterId(),
            );
        } catch (\DomainException $exception) {
            $form->get('responsibilityCenterId')->addError(new FormError($exception->getMessage()));
        }
    }
}
