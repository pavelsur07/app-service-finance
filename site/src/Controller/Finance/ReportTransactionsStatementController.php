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

class ReportTransactionsStatementController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly CashTransactionRepository $trxRepo,
        private readonly MoneyAccountDailyBalanceRepository $dailyRepo,
        private readonly MoneyAccountRepository $accountRepo
    ) {
    }

    #[Route('/finance/reports/transactions-statement', name: 'report_transactions_statement_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompany->getActiveCompany();

        $groupParam = $request->query->get('group', 'day');
        $group = is_string($groupParam) ? $groupParam : 'day';
        $allowedGroups = ['day', 'week', 'month', 'quarter'];
        if (!in_array($group, $allowedGroups, true)) {
            $group = 'day';
        }

        $scopeParam = $request->query->get('scope', 'company');
        $scope = is_string($scopeParam) ? $scopeParam : 'company';
        if (!in_array($scope, ['company', 'global'], true)) {
            $scope = 'company';
        }

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

        $includeRaw = $request->query->get('include_empty_periods', '0');
        $includeEmpty = is_string($includeRaw) ? $includeRaw === '1' : false;
        $expandRaw = $request->query->get('expand_transactions', '1');
        $expandTransactions = is_string($expandRaw) ? $expandRaw !== '0' : true;

        $accountRaw = $request->query->get('account');
        $accountParam = is_string($accountRaw) && $accountRaw !== '' ? $accountRaw : null;
        $categoryRaw = $request->query->get('category');
        $categoryParam = is_string($categoryRaw) && $categoryRaw !== '' ? $categoryRaw : null;

        $accounts = $this->accountRepo->findBy(['company' => $company], ['name' => 'ASC']);
        $accountOptions = [];
        $accountIds = [];
        $selectedAccount = null;
        foreach ($accounts as $account) {
            /** @var MoneyAccount $account */
            $accountOptions[] = [
                'id' => $account->getId(),
                'name' => sprintf('%s (%s)', $account->getName(), $account->getCurrency()),
            ];
            $accountIds[] = $account->getId();
            if ($accountParam && $account->getId() === $accountParam) {
                $selectedAccount = $account;
            }
        }

        if ($selectedAccount) {
            $accountIds = [$selectedAccount->getId()];
        } elseif ($accountParam) {
            // Reset invalid account filter
            $accountParam = null;
        }

        $periods = $this->buildPeriods($from, $to, $group);

        $balances = $this->fetchDailyBalances($company, $from, $to, $accountIds);
        $transactionsData = $this->fetchTransactions($company, $from, $to, $accountParam, $categoryParam);
        $transactions = $transactionsData['transactions'];
        $categoryOptions = $transactionsData['categories'];

        if ($categoryParam && !array_filter($categoryOptions, fn ($item) => $item['id'] === $categoryParam)) {
            $categoryOptions[] = ['id' => $categoryParam, 'name' => 'Выбранная категория'];
        }

        $aggregation = $this->aggregateByBuckets(
            $periods,
            $balances,
            $transactions,
            $group,
            $includeEmpty,
            $expandTransactions
        );

        $filters = [
            'date_from' => $from,
            'date_to' => $to,
            'group' => $group,
            'scope' => $scope,
            'account' => $accountParam,
            'category' => $categoryParam,
            'include_empty_periods' => $includeEmpty,
            'expand_transactions' => $expandTransactions,
        ];

        return $this->render('report/transactions_statement.html.twig', [
            'accounts' => $accountOptions,
            'categories' => $categoryOptions,
            'summary' => $aggregation['summary'],
            'groupBy' => $group,
            'buckets' => $aggregation['buckets'],
            'filters' => $filters,
        ]);
    }

    /**
     * @return array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable, label_start: \DateTimeImmutable, label_end: \DateTimeImmutable}>
     */
    private function buildPeriods(\DateTimeImmutable $from, \DateTimeImmutable $to, string $group): array
    {
        $periods = [];
        $current = $from;

        while ($current <= $to) {
            switch ($group) {
                case 'week':
                    $labelStart = $current;
                    $labelEnd = $current->modify('+6 days');
                    break;
                case 'month':
                    $labelStart = new \DateTimeImmutable($current->format('Y-m-01'));
                    $labelEnd = $labelStart->modify('+1 month -1 day');
                    break;
                case 'quarter':
                    $month = (int) $current->format('n');
                    $quarter = intdiv($month - 1, 3);
                    $startMonth = $quarter * 3 + 1;
                    $labelStart = new \DateTimeImmutable(sprintf('%s-%02d-01', $current->format('Y'), $startMonth));
                    $labelEnd = $labelStart->modify('+3 months -1 day');
                    break;
                case 'day':
                default:
                    $labelStart = $current;
                    $labelEnd = $current;
                    break;
            }

            if ($labelStart > $to) {
                break;
            }

            if ($labelEnd < $from) {
                $current = $labelEnd->modify('+1 day');
                continue;
            }

            $start = $labelStart < $from ? $from : $labelStart;
            $end = $labelEnd > $to ? $to : $labelEnd;

            $periods[] = [
                'start' => $start,
                'end' => $end,
                'label_start' => $labelStart,
                'label_end' => $labelEnd,
            ];

            $current = $labelEnd->modify('+1 day');
        }

        return $periods;
    }

    private function labelForPeriod(array $bucket, string $group): string
    {
        /** @var \DateTimeImmutable $start */
        $start = $bucket['label_start'] ?? $bucket['start'];
        /** @var \DateTimeImmutable $end */
        $end = $bucket['label_end'] ?? $bucket['end'];

        return match ($group) {
            'week' => sprintf('%s — %s', $start->format('d.m.Y'), $end->format('d.m.Y')),
            'month' => $this->formatMonthLabel($start),
            'quarter' => $this->formatQuarterLabel($start),
            default => $start->format('d.m.Y'),
        };
    }

    private function formatMonthLabel(\DateTimeImmutable $date): string
    {
        $months = [
            1 => 'Январь',
            2 => 'Февраль',
            3 => 'Март',
            4 => 'Апрель',
            5 => 'Май',
            6 => 'Июнь',
            7 => 'Июль',
            8 => 'Август',
            9 => 'Сентябрь',
            10 => 'Октябрь',
            11 => 'Ноябрь',
            12 => 'Декабрь',
        ];

        $monthNum = (int) $date->format('n');
        $name = $months[$monthNum] ?? $date->format('F');

        return sprintf('%s %s', $name, $date->format('Y'));
    }

    private function formatQuarterLabel(\DateTimeImmutable $date): string
    {
        $quarter = intdiv((int) $date->format('n') - 1, 3) + 1;

        return sprintf('%d квартал %s', $quarter, $date->format('Y'));
    }

    /**
     * @param list<string> $accountIds
     * @return array<string, array<string, array{opening: float, closing: float}>>
     */
    private function fetchDailyBalances(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds): array
    {
        if ($from > $to) {
            return [];
        }

        $qb = $this->dailyRepo->createQueryBuilder('b')
            ->innerJoin('b.moneyAccount', 'a')
            ->addSelect('a')
            ->where('b.company = :company')
            ->andWhere('b.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.date', 'ASC');

        if (!empty($accountIds)) {
            $qb->andWhere('a.id IN (:accountIds)')
                ->setParameter('accountIds', $accountIds);
        }

        $rows = $qb->getQuery()->getResult();

        $result = [];
        foreach ($rows as $row) {
            /** @var \App\Entity\MoneyAccountDailyBalance $row */
            $dateKey = $row->getDate()->format('Y-m-d');
            $accountId = $row->getMoneyAccount()->getId();
            $result[$dateKey][$accountId] = [
                'opening' => (float) $row->getOpeningBalance(),
                'closing' => (float) $row->getClosingBalance(),
            ];
        }

        return $result;
    }

    /**
     * @return array{transactions: list<array{date: \DateTimeImmutable, document: ?string, counterparty: ?string, description: ?string, amount: float}>, categories: list<array{id: string, name: string}>}
     */
    private function fetchTransactions(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $accountId,
        ?string $categoryId
    ): array {
        $qb = $this->trxRepo->createQueryBuilder('t')
            ->leftJoin('t.counterparty', 'counterparty')
            ->addSelect('counterparty')
            ->leftJoin('t.cashflowCategory', 'category')
            ->addSelect('category')
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

        $transactions = [];
        $categories = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $transaction = $row[0] ?? null;
            } else {
                $transaction = $row;
            }

            if (!$transaction instanceof \App\Entity\CashTransaction) {
                continue;
            }

            $amount = (float) $transaction->getAmount();
            if ($transaction->getDirection() === CashDirection::OUTFLOW) {
                $amount = -abs($amount);
            } else {
                $amount = abs($amount);
            }

            $category = $transaction->getCashflowCategory();
            if ($category) {
                $categories[$category->getId()] = $category->getName();
            }

            $transactions[] = [
                'date' => $transaction->getOccurredAt(),
                'document' => $transaction->getExternalId(),
                'counterparty' => $transaction->getCounterparty() ? $transaction->getCounterparty()->getName() : null,
                'description' => $transaction->getDescription(),
                'amount' => round($amount, 2),
            ];
        }

        asort($categories, SORT_LOCALE_STRING);

        $categoryOptions = [];
        foreach ($categories as $id => $name) {
            $categoryOptions[] = ['id' => $id, 'name' => $name ?? ''];
        }

        return [
            'transactions' => $transactions,
            'categories' => $categoryOptions,
        ];
    }

    /**
     * @param array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable, label_start: \DateTimeImmutable, label_end: \DateTimeImmutable}> $periods
     * @param array<string, array<string, array{opening: float, closing: float}>> $balances
     * @param list<array{date: \DateTimeImmutable, document: ?string, counterparty: ?string, description: ?string, amount: float}> $transactions
     * @return array{summary: array{opening: float, income: float, expense: float, closing: float}, buckets: list<array{label: string, opening: float, closing_calc: float, closing_fact: ?float, check_ok: bool, check_diff: ?float, transactions: list<array{date: \DateTimeInterface, doc: ?string, counterparty: ?string, description: ?string, amount: float, balance_after: float}>}>}
     */
    private function aggregateByBuckets(
        array $periods,
        array $balances,
        array $transactions,
        string $group,
        bool $includeEmpty,
        bool $expandTransactions
    ): array {
        $buckets = [];
        $summary = [
            'opening' => 0.0,
            'income' => 0.0,
            'expense' => 0.0,
            'closing' => 0.0,
        ];
        $hasOpening = false;

        $txIndex = 0;
        $txCount = count($transactions);

        foreach ($periods as $bucket) {
            /** @var \DateTimeImmutable $start */
            $start = $bucket['start'];
            /** @var \DateTimeImmutable $end */
            $end = $bucket['end'];

            $startKey = $start->format('Y-m-d');
            $endKey = $end->format('Y-m-d');

            $opening = 0.0;
            $openingHasData = false;
            if (isset($balances[$startKey])) {
                foreach ($balances[$startKey] as $row) {
                    $opening += $row['opening'];
                    $openingHasData = true;
                }
            }
            $opening = round($opening, 2);

            $closingFact = null;
            $closingHasData = false;
            if (isset($balances[$endKey])) {
                $closingSum = 0.0;
                foreach ($balances[$endKey] as $row) {
                    $closingSum += $row['closing'];
                    $closingHasData = true;
                }
                if ($closingHasData) {
                    $closingFact = round($closingSum, 2);
                }
            }

            $running = $opening;
            $periodTransactions = [];
            $hasTransactions = false;

            while ($txIndex < $txCount) {
                $tx = $transactions[$txIndex];
                $txDate = $tx['date'];

                if ($txDate < $start) {
                    $txIndex++;
                    continue;
                }

                if ($txDate > $end) {
                    break;
                }

                $hasTransactions = true;
                $amount = $tx['amount'];
                $running = round($running + $amount, 2);

                if ($expandTransactions) {
                    $periodTransactions[] = [
                        'date' => $txDate,
                        'doc' => $tx['document'],
                        'counterparty' => $tx['counterparty'],
                        'description' => $tx['description'],
                        'amount' => round($amount, 2),
                        'balance_after' => $running,
                    ];
                }

                if ($amount > 0) {
                    $summary['income'] += $amount;
                } elseif ($amount < 0) {
                    $summary['expense'] += abs($amount);
                }

                $txIndex++;
            }

            $closingCalc = round($running, 2);

            if (!$includeEmpty && !$hasTransactions && !$openingHasData && !$closingHasData) {
                continue;
            }

            if (!$hasOpening) {
                $summary['opening'] = $opening;
                $hasOpening = true;
            }

            $checkOk = false;
            $checkDiff = null;
            if ($closingFact !== null) {
                $diff = round(abs($closingCalc - $closingFact), 2);
                $checkOk = $diff < 0.01;
                $checkDiff = $checkOk ? null : $diff;
            }

            $summary['closing'] = $closingFact ?? $closingCalc;

            $buckets[] = [
                'label' => $this->labelForPeriod($bucket, $group),
                'opening' => $opening,
                'closing_calc' => $closingCalc,
                'closing_fact' => $closingFact,
                'check_ok' => $checkOk,
                'check_diff' => $checkDiff,
                'transactions' => $expandTransactions ? $periodTransactions : [],
            ];
        }

        if (!$hasOpening) {
            $summary['opening'] = 0.0;
        }

        $summary['income'] = round($summary['income'], 2);
        $summary['expense'] = round($summary['expense'], 2);
        $summary['closing'] = round($summary['closing'], 2);

        return [
            'summary' => $summary,
            'buckets' => $buckets,
        ];
    }
}
