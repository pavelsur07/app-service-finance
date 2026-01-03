<?php

namespace App\Controller\Finance;

use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Enum\CashDirection;
use App\Repository\CashTransactionRepository;
use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReportTransactionsDebugController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly CashTransactionRepository $trxRepo,
        private readonly MoneyAccountDailyBalanceRepository $dailyRepo,
        private readonly MoneyAccountRepository $accountRepo,
    ) {
    }

    #[Route('/finance/reports/transactions-debug', name: 'report_transactions_debug_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompany->getActiveCompany();

        // Параметры
        $today = new \DateTimeImmutable('today');
        $defaultFrom = new \DateTimeImmutable($today->format('Y-m-01'));
        $defaultTo = $defaultFrom->modify('+1 month -1 day');

        $fromParam = $request->query->get('date_from');
        $toParam = $request->query->get('date_to');

        try {
            $from = $fromParam ? new \DateTimeImmutable($fromParam) : $defaultFrom;
        } catch (\Exception $e) {
            $from = $defaultFrom;
        }

        try {
            $to = $toParam ? new \DateTimeImmutable($toParam) : $defaultTo;
        } catch (\Exception $e) {
            $to = $defaultTo;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $accountRaw = $request->query->get('account');
        $accountParam = is_string($accountRaw) && '' !== $accountRaw ? $accountRaw : null;

        $categoryRaw = $request->query->get('category');
        $categoryParam = is_string($categoryRaw) && '' !== $categoryRaw ? $categoryRaw : null;

        // Справочники счетов (для фильтра)
        $accounts = $this->accountRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $accountOptions = [];
        $selectedAccount = null;
        foreach ($accounts as $account) {
            /* @var MoneyAccount $account */
            $accountOptions[] = [
                'id' => $account->getId(),
                'name' => sprintf('%s (%s)', $account->getName(), $account->getCurrency()),
            ];
            if ($accountParam && $account->getId() === $accountParam) {
                $selectedAccount = $account;
            }
        }
        if ($accountParam && !$selectedAccount) {
            // если передан несуществующий id — сбрасываем
            $accountParam = null;
        }

        // 1) ТОЛЬКО ТРАНЗАКЦИИ (как есть)
        $transactions = $this->fetchTransactionsRaw($company, $from, $to, $accountParam, $categoryParam);

        // 2) ТОЛЬКО ФАКТИЧЕСКИЕ ОСТАТКИ (MoneyAccountDailyBalance) по датам/счетам
        $balances = $this->fetchDailyBalancesRaw($company, $from, $to, $accountParam);

        // категории для фильтра (по факту встретившиеся в выборке)
        $categoryOptions = $this->extractCategoriesFromTransactions($transactions);

        $filters = [
            'date_from' => $from,
            'date_to' => $to,
            'account' => $accountParam,
            'category' => $categoryParam,
        ];

        return $this->render('report/transactions_debug.html.twig', [
            'accounts' => $accountOptions,
            'categories' => $categoryOptions,
            'filters' => $filters,
            'transactions' => $transactions,
            'balances' => $balances,
        ]);
    }

    /**
     * Сырые транзакции — без подсчётов. Сумма со знаком по направлению.
     *
     * @return list<array{
     *   date: \DateTimeImmutable,
     *   account_id: string,
     *   account_name: string,
     *   document: ?string,
     *   counterparty: ?string,
     *   category_id: ?string,
     *   category_name: ?string,
     *   description: ?string,
     *   amount: float
     * }>
     */
    private function fetchTransactionsRaw(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $accountId,
        ?string $categoryId,
    ): array {
        $qb = $this->trxRepo->createQueryBuilder('t')
            ->leftJoin('t.moneyAccount', 'acc')
            ->addSelect('acc')
            ->leftJoin('t.counterparty', 'cp')
            ->addSelect('cp')
            ->leftJoin('t.cashflowCategory', 'cat')
            ->addSelect('cat')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->orderBy('t.occurredAt', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if ($accountId) {
            $qb->andWhere('IDENTITY(t.moneyAccount) = :accountId')
                ->setParameter('accountId', $accountId);
        }
        if ($categoryId) {
            $qb->andWhere('IDENTITY(t.cashflowCategory) = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        $rows = $qb->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            $trx = is_array($row) ? ($row[0] ?? null) : $row;
            if (!$trx instanceof \App\Entity\CashTransaction) {
                continue;
            }

            $amount = (float) $trx->getAmount();
            if (CashDirection::OUTFLOW === $trx->getDirection()) {
                $amount = -abs($amount);
            } else {
                $amount = abs($amount);
            }

            $acc = $trx->getMoneyAccount();
            $cat = $trx->getCashflowCategory();
            $cp = $trx->getCounterparty();

            $result[] = [
                'date' => $trx->getOccurredAt(),
                'account_id' => $acc ? $acc->getId() : '',
                'account_name' => $acc ? $acc->getName() : '',
                'document' => $trx->getExternalId(),
                'counterparty' => $cp ? $cp->getName() : null,
                'category_id' => $cat ? $cat->getId() : null,
                'category_name' => $cat ? ($cat->getName() ?? '') : null,
                'description' => $trx->getDescription(),
                'amount' => round($amount, 2),
            ];
        }

        return $result;
    }

    /**
     * Сырые ежедневные остатки из MoneyAccountDailyBalance — без подсчётов.
     * Если выбран счёт, отдаём только его; иначе — все счета компании.
     *
     * @return list<array{
     *   date: \DateTimeImmutable,
     *   account_id: string,
     *   account_name: string,
     *   opening: float,
     *   closing: float
     * }>
     */
    private function fetchDailyBalancesRaw(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $accountId,
    ): array {
        $qb = $this->dailyRepo->createQueryBuilder('b')
            ->innerJoin('b.moneyAccount', 'a')
            ->addSelect('a')
            ->where('b.company = :company')
            ->andWhere('b.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.date', 'ASC')
            ->addOrderBy('a.name', 'ASC');

        if ($accountId) {
            $qb->andWhere('a.id = :accountId')
                ->setParameter('accountId', $accountId);
        }

        $rows = $qb->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            /** @var \App\Entity\MoneyAccountDailyBalance $row */
            $acc = $row->getMoneyAccount();
            $result[] = [
                'date' => $row->getDate(),
                'account_id' => $acc ? $acc->getId() : '',
                'account_name' => $acc ? $acc->getName() : '',
                'opening' => (float) $row->getOpeningBalance(),
                'closing' => (float) $row->getClosingBalance(),
            ];
        }

        return $result;
    }

    /**
     * Строим список категорий на основе выборки транзакций.
     *
     * @param list<array{category_id: ?string, category_name: ?string}> $transactions
     *
     * @return list<array{id: string, name: string}>
     */
    private function extractCategoriesFromTransactions(array $transactions): array
    {
        $map = [];
        foreach ($transactions as $t) {
            if (!empty($t['category_id'])) {
                $map[$t['category_id']] = $t['category_name'] ?? '';
            }
        }
        asort($map, \SORT_LOCALE_STRING);

        $out = [];
        foreach ($map as $id => $name) {
            $out[] = ['id' => $id, 'name' => $name];
        }

        return $out;
    }
}
