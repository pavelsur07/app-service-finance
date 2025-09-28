<?php

namespace App\Controller\Finance;

use App\Repository\CashTransactionRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/reports/_debug/transactions-raw', name: 'report_debug_transactions_raw', methods: ['GET'])]
class DebugTransactionsRawController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly CashTransactionRepository $trxRepo,
        private readonly MoneyAccountRepository $accountRepo,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompany->getActiveCompany();

        $from = new \DateTimeImmutable($request->query->get('date_from', 'first day of this month'));
        $to = new \DateTimeImmutable($request->query->get('date_to', 'last day of this month'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $accountId = $request->query->get('account') ?: null;

        $qb = $this->trxRepo->createQueryBuilder('t')
            ->leftJoin('t.moneyAccount', 'a')->addSelect('a')
            ->leftJoin('t.counterparty', 'c')->addSelect('c')
            ->leftJoin('t.cashflowCategory', 'cat')->addSelect('cat')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->orderBy('t.occurredAt', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if ($accountId) {
            $qb->andWhere('IDENTITY(t.moneyAccount) = :acc')->setParameter('acc', $accountId);
        }

        $rows = $qb->getQuery()->getResult();

        $txs = [];
        foreach ($rows as $t) {
            $amount = (float) $t->getAmount();
            $amount = 'OUTFLOW' === $t->getDirection()->value ? -abs($amount) : +abs($amount);

            $txs[] = [
                'date' => $t->getOccurredAt(),
                'account' => $t->getMoneyAccount()?->getName(),
                'counterparty' => $t->getCounterparty()?->getName(),
                'category' => $t->getCashflowCategory()?->getName(),
                'document' => $t->getExternalId(),
                'description' => $t->getDescription(),
                'amount' => $amount,
            ];
        }

        $accountOptions = $this->accountRepo->findBy(['company' => $company], ['name' => 'ASC']);

        return $this->render('report/debug_transactions_raw.html.twig', [
            'filters' => [
                'date_from' => $from,
                'date_to' => $to,
                'account' => $accountId,
            ],
            'accounts' => $accountOptions,
            'txs' => $txs,
        ]);
    }
}
