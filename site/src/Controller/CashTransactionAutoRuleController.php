<?php

namespace App\Controller;

use App\Entity\CashTransactionAutoRule;
use App\Enum\CashTransactionAutoRuleAction;
use App\Enum\CashTransactionAutoRuleOperationType;
use App\Enum\CashDirection;
use App\Enum\CashTransactionAutoRuleConditionField;
use App\Enum\CashTransactionAutoRuleConditionOperator;
use App\Form\CashTransactionAutoRuleType;
use App\Repository\CashTransactionAutoRuleRepository;
use App\Repository\CashTransactionRepository;
use App\Repository\CashflowCategoryRepository;
use App\Repository\CounterpartyRepository;
use App\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cash-transaction-auto-rules')]
class CashTransactionAutoRuleController extends AbstractController
{
    #[Route('/', name: 'cash_transaction_auto_rule_index', methods: ['GET'])]
    public function index(CashTransactionAutoRuleRepository $repo, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $items = $repo->findByCompany($company);

        return $this->render('cash_transaction_auto_rule/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/new', name: 'cash_transaction_auto_rule_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
        CashflowCategoryRepository $categoryRepo,
        CounterpartyRepository $counterpartyRepo
    ): Response {
        $company = $companyService->getActiveCompany();
        $categories = $categoryRepo->findTreeByCompany($company);
        $counterparties = $counterpartyRepo->findBy(['company' => $company]);

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
        CounterpartyRepository $counterpartyRepo
    ): Response {
        $company = $companyService->getActiveCompany();
        $rule = $repo->find($id);
        if (!$rule || $rule->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $categories = $categoryRepo->findTreeByCompany($company);
        $counterparties = $counterpartyRepo->findBy(['company' => $company]);
        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, [
            'categories' => $categories,
            'counterparties' => $counterparties,
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
        CashTransactionRepository $txRepo
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
        if ($rule->getOperationType() !== CashTransactionAutoRuleOperationType::ANY) {
            $dir = $rule->getOperationType() === CashTransactionAutoRuleOperationType::INFLOW
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
                    $qb->andWhere('LOWER(cp.name) LIKE :'.$p)
                       ->setParameter($p, '%'.mb_strtolower(str_replace('ё','е',(string)$cond->getValue())).'%');
                    break;

                case CashTransactionAutoRuleConditionField::INN:
                    $needJoinCp = true;
                    $qb->andWhere('cp.inn = :'.$p)->setParameter($p, preg_replace('/\D+/', '', (string)$cond->getValue()));
                    break;

                case CashTransactionAutoRuleConditionField::DATE:
                    if ($cond->getOperator() === CashTransactionAutoRuleConditionOperator::BETWEEN) {
                        $qb->andWhere('t.occurredAt BETWEEN :'.$p.'From AND :'.$p.'To')
                           ->setParameter($p.'From', new \DateTimeImmutable($cond->getValue().' 00:00:00'))
                           ->setParameter($p.'To',   new \DateTimeImmutable($cond->getValueTo().' 23:59:59'));
                    } else {
                        // трактуем EQUAL как «в пределах суток»
                        $qb->andWhere('t.occurredAt BETWEEN :'.$p.'From AND :'.$p.'To')
                           ->setParameter($p.'From', new \DateTimeImmutable($cond->getValue().' 00:00:00'))
                           ->setParameter($p.'To',   new \DateTimeImmutable($cond->getValue().' 23:59:59'));
                    }
                    break;

                case CashTransactionAutoRuleConditionField::AMOUNT:
                    $val = (float)str_replace(',', '.', (string)$cond->getValue());
                    if ($cond->getOperator() === CashTransactionAutoRuleConditionOperator::BETWEEN) {
                        $val2 = (float)str_replace(',', '.', (string)$cond->getValueTo());
                        $qb->andWhere('t.amount BETWEEN :'.$p.'A AND :'.$p.'B')
                           ->setParameter($p.'A', $val)->setParameter($p.'B', $val2);
                    } elseif ($cond->getOperator() === CashTransactionAutoRuleConditionOperator::GREATER_THAN) {
                        $qb->andWhere('t.amount > :'.$p)->setParameter($p, $val);
                    } elseif ($cond->getOperator() === CashTransactionAutoRuleConditionOperator::LESS_THAN) {
                        $qb->andWhere('t.amount < :'.$p)->setParameter($p, $val);
                    } else { // EQUAL
                        $qb->andWhere('t.amount = :'.$p)->setParameter($p, $val);
                    }
                    break;

                case CashTransactionAutoRuleConditionField::DESCRIPTION:
                    $qb->andWhere('LOWER(COALESCE(t.description, \'\')) LIKE :'.$p)
                       ->setParameter($p, '%'.mb_strtolower(str_replace('ё','е',(string)$cond->getValue())).'%');
                    break;
            }
        }
        if ($needJoinCp) {
            $qb->leftJoin('t.counterparty', 'cp');
        }

        // Ограничение предпросмотра
        $limit = min((int)$request->query->get('limit', 200), 1000);
        $transactions = $qb->setMaxResults($limit)->getQuery()->getResult();

        return $this->render('cash_transaction_auto_rule/check.html.twig', [
            'rule' => $rule,
            'transactions' => $transactions,
            'limit' => $limit,
        ]);
    }

    #[Route('/{id}/delete', name: 'cash_transaction_auto_rule_delete', methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        CashTransactionAutoRuleRepository $repo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService
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
