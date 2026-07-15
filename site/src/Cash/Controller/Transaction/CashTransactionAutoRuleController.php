<?php

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashTransactionAutoRulePreviewFilter;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Form\Transaction\CashTransactionAutoRuleType;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ): Response {
        $company = $companyService->getActiveCompany();
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
                    CashTransactionAutoRuleAction::FILL => 'Заполнить поля операции',
                    CashTransactionAutoRuleAction::UPDATE => 'Изменить поля операции',
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
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
        CashflowCategoryRepository $categoryRepo,
        CounterpartyRepository $counterpartyRepo,
        ProjectDirectionRepository $projectDirectionRepo,
    ): Response {
        $company = $companyService->getActiveCompany();
        $categories = $categoryRepo->findTreeByCompany($company);
        $counterparties = $counterpartyRepo->findBy(['company' => $company]);
        $projectDirections = $projectDirectionRepo->findBy(['company' => $company], ['name' => 'ASC']);

        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            '',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY
        );

        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, [
            'categories' => $categories,
            'counterparties' => $counterparties,
            'projectDirections' => $projectDirections,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($rule);
            $em->flush();

            return $this->redirectToRoute('cash_transaction_auto_rule_index');
        }

        return $this->render('cash_transaction_auto_rule/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'cash_transaction_auto_rule_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $id,
        Request $request,
        CashTransactionAutoRuleRepository $repo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
        CashflowCategoryRepository $categoryRepo,
        CounterpartyRepository $counterpartyRepo,
        ProjectDirectionRepository $projectDirectionRepo,
    ): Response {
        $company = $companyService->getActiveCompany();
        $rule = $repo->find($id);
        if (!$rule || $rule->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $categories = $categoryRepo->findTreeByCompany($company);
        $counterparties = $counterpartyRepo->findBy(['company' => $company]);
        $projectDirections = $projectDirectionRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, [
            'categories' => $categories,
            'counterparties' => $counterparties,
            'projectDirections' => $projectDirections,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            return $this->redirectToRoute('cash_transaction_auto_rule_index');
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
        $rule = $ruleRepo->find($id);
        if (!$rule || $rule->getCompany() !== $company) {
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
            ], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        $qb = $txRepo->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->setParameter('company', $company)
            ->innerJoin('t.moneyAccount', 'moneyAccount')
            ->addSelect('moneyAccount')
            ->leftJoin('t.counterparty', 'counterparty')
            ->addSelect('counterparty')
            ->leftJoin('t.cashflowCategory', 'cashflowCategory')
            ->addSelect('cashflowCategory')
            ->leftJoin('t.projectDirection', 'projectDirection')
            ->addSelect('projectDirection')
            ->orderBy('t.occurredAt', 'DESC');

        $qb
            ->andWhere('t.occurredAt >= :from')
            ->setParameter('from', $filter->dateFrom)
            ->andWhere('t.occurredAt <= :to')
            ->setParameter('to', $filter->dateTo->setTime(23, 59, 59));

        $previewRows = $autoRuleService->previewRule(
            $rule,
            $qb->getQuery()->toIterable(),
            $filter->limit,
        );

        return $this->render('cash_transaction_auto_rule/check.html.twig', [
            'rule' => $rule,
            'previewRows' => $previewRows,
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
    ): Response {
        $company = $companyService->getActiveCompany();

        /** @var CashTransaction|null $t */
        $t = $txRepo->find($transactionId);
        if (!$t || $t->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $skipReason = $autoRuleService->getSkipReason($t);
        $match = null === $skipReason ? $autoRuleService->match($t) : null;

        return $this->render('cash_transaction_auto_rule/_auto_rule_modal_body.html.twig', [
            'transaction' => $t,
            'rule' => $match?->rule,
            'conflictingRules' => $match?->conflictingRules ?? [],
            'skipReason' => $skipReason,
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
        $t = $txRepo->find($transactionId);
        if (!$t || $t->getCompany() !== $company) {
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
        if ($match->hasConflict()) {
            return new JsonResponse([
                'ok' => false,
                'changed' => false,
                'reason' => 'conflict',
                'message' => 'Обнаружен конфликт автоправил с одинаковым приоритетом',
            ], 200);
        }

        $requestedRuleId = (string) $request->request->get('ruleId', '');
        $rule = $match->rule;
        if (!$rule || ('' !== $requestedRuleId && $requestedRuleId !== $rule->getId())) {
            return new JsonResponse(['ok' => false, 'message' => 'Подходящее правило не найдено'], 200);
        }

        $changed = $autoRuleService->applyRule($rule, $t);

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
    ): Response {
        $company = $companyService->getActiveCompany();
        $rule = $repo->find($id);
        if (!$rule || $rule->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$rule->getId(), $request->request->get('_token'))) {
            $em->remove($rule);
            $em->flush();
        }

        return $this->redirectToRoute('cash_transaction_auto_rule_index');
    }
}
