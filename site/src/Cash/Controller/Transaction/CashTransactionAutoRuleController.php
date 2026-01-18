<?php

namespace App\Cash\Controller\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Form\Transaction\CashTransactionAutoRuleType;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Enum\CashTransactionAutoRuleOperationType;
use App\Repository\CounterpartyRepository;
use App\Repository\ProjectDirectionRepository;
use App\Service\ActiveCompanyService;
use App\Util\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    ): Response {
        $company = $companyService->getActiveCompany();
        $rule = $ruleRepo->find($id);
        if (!$rule || $rule->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $qb = $txRepo->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->setParameter('company', $company)
            ->orderBy('t.occurredAt', 'DESC');

        // 1) Фильтр по типу операции (если не ANY)
        if (CashTransactionAutoRuleOperationType::ANY !== $rule->getOperationType()) {
            $dir = CashTransactionAutoRuleOperationType::INFLOW === $rule->getOperationType()
                ? CashDirection::INFLOW
                : CashDirection::OUTFLOW;
            $qb->andWhere('t.direction = :dir')->setParameter('dir', $dir);
        }

        // Опциональные границы периода предпросмотра (?dateFrom, ?dateTo)
        if ($dFrom = $request->query->get('dateFrom')) {
            $qb->andWhere('t.occurredAt >= :from')->setParameter('from', new \DateTimeImmutable($dFrom.' 00:00:00'));
        }
        if ($dTo = $request->query->get('dateTo')) {
            $qb->andWhere('t.occurredAt <= :to')->setParameter('to', new \DateTimeImmutable($dTo.' 23:59:59'));
        }

        // 2) Условия правила (AND)
        $needJoinCp = false;
        foreach ($rule->getConditions() as $idx => $cond) {
            $p = 'p'.$idx; // параметр
            switch ($cond->getField()) {
                case CashTransactionAutoRuleConditionField::COUNTERPARTY:
                    // operator = EQUAL; значение — entity
                    $qb->andWhere('t.counterparty = :'.$p)->setParameter($p, $cond->getCounterparty());
                    break;

                case CashTransactionAutoRuleConditionField::COUNTERPARTY_NAME:
                    $needJoinCp = true;
                    $qb->andWhere("REPLACE(LOWER(COALESCE(cp.name, '')), 'ё', 'е') LIKE :$p")
                       ->setParameter($p, '%'.StringNormalizer::normalize((string) $cond->getValue()).'%');
                    break;

                case CashTransactionAutoRuleConditionField::INN:
                    $needJoinCp = true;
                    $qb->andWhere('cp.inn = :'.$p)->setParameter($p, preg_replace('/\D+/', '', (string) $cond->getValue()));
                    break;

                case CashTransactionAutoRuleConditionField::DATE:
                    if (CashTransactionAutoRuleConditionOperator::BETWEEN === $cond->getOperator()) {
                        $qb->andWhere('t.occurredAt BETWEEN :'.$p.'From AND :'.$p.'To')
                           ->setParameter($p.'From', new \DateTimeImmutable($cond->getValue().' 00:00:00'))
                           ->setParameter($p.'To', new \DateTimeImmutable($cond->getValueTo().' 23:59:59'));
                    } else {
                        // трактуем EQUAL как «в пределах суток»
                        $qb->andWhere('t.occurredAt BETWEEN :'.$p.'From AND :'.$p.'To')
                           ->setParameter($p.'From', new \DateTimeImmutable($cond->getValue().' 00:00:00'))
                           ->setParameter($p.'To', new \DateTimeImmutable($cond->getValue().' 23:59:59'));
                    }
                    break;

                case CashTransactionAutoRuleConditionField::AMOUNT:
                    $val = (float) str_replace(',', '.', (string) $cond->getValue());
                    if (CashTransactionAutoRuleConditionOperator::BETWEEN === $cond->getOperator()) {
                        $val2 = (float) str_replace(',', '.', (string) $cond->getValueTo());
                        $qb->andWhere('t.amount BETWEEN :'.$p.'A AND :'.$p.'B')
                           ->setParameter($p.'A', $val)->setParameter($p.'B', $val2);
                    } elseif (CashTransactionAutoRuleConditionOperator::GREATER_THAN === $cond->getOperator()) {
                        $qb->andWhere('t.amount > :'.$p)->setParameter($p, $val);
                    } elseif (CashTransactionAutoRuleConditionOperator::LESS_THAN === $cond->getOperator()) {
                        $qb->andWhere('t.amount < :'.$p)->setParameter($p, $val);
                    } else { // EQUAL
                        $qb->andWhere('t.amount = :'.$p)->setParameter($p, $val);
                    }
                    break;

                case CashTransactionAutoRuleConditionField::DESCRIPTION:
                    $qb->andWhere("REPLACE(LOWER(COALESCE(t.description, '')), 'ё', 'е') LIKE :$p")
                       ->setParameter($p, '%'.StringNormalizer::normalize((string) $cond->getValue()).'%');
                    break;
            }
        }
        if ($needJoinCp) {
            $qb->leftJoin('t.counterparty', 'cp');
        }

        // Ограничение предпросмотра
        $limit = min((int) $request->query->get('limit', 200), 1000);
        $transactions = $qb->setMaxResults($limit)->getQuery()->getResult();

        return $this->render('cash_transaction_auto_rule/check.html.twig', [
            'rule' => $rule,
            'transactions' => $transactions,
            'limit' => $limit,
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

        $rule = $autoRuleService->findMatchingRule($t);

        return $this->render('cash_transaction_auto_rule/_auto_rule_modal_body.html.twig', [
            'transaction' => $t,
            'rule' => $rule,
        ]);
    }

    #[Route('/apply/{transactionId}', name: 'cash_transaction_auto_rule_apply_one', methods: ['POST'])]
    public function applyOne(
        string $transactionId,
        Request $request,
        ActiveCompanyService $companyService,
        CashTransactionRepository $txRepo,
        CashTransactionAutoRuleRepository $ruleRepo,
        CashTransactionAutoRuleService $autoRuleService,
    ): Response {
        $company = $companyService->getActiveCompany();

        /** @var CashTransaction|null $t */
        $t = $txRepo->find($transactionId);
        if (!$t || $t->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $ruleId = (string) $request->request->get('ruleId', '');
        $rule = $ruleId ? $ruleRepo->find($ruleId) : null;

        // safety: если id не пришёл — пересчитаем подбор прямо сейчас
        if (!$rule) {
            $rule = $autoRuleService->findMatchingRule($t);
        }
        if (!$rule || $rule->getCompany() !== $company) {
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
