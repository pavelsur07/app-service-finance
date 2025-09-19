<?php

namespace App\Controller\Finance;

use App\Entity\MoneyAccount;
use App\Enum\MoneyAccountType;
use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/reports/daily-balances')]
class ReportDailyBalancesController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private MoneyAccountRepository $accountRepository,
        private MoneyAccountDailyBalanceRepository $balanceRepository,
    ) {
    }

    #[Route('', name: 'report_daily_balances_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        $to = $toParam ? new \DateTimeImmutable($toParam) : new \DateTimeImmutable('today');
        $from = $fromParam ? new \DateTimeImmutable($fromParam) : $to->modify('-13 days');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $period = new \DatePeriod($from, new \DateInterval('P1D'), $to->modify('+1 day'));
        $dates = [];
        foreach ($period as $d) {
            $dates[] = $d->format('Y-m-d');
        }

        $accounts = $this->accountRepository->findBy(['company' => $company], ['type' => 'ASC', 'name' => 'ASC']);
        $accountsByType = [
            MoneyAccountType::BANK->value => [],
            MoneyAccountType::CASH->value => [],
            MoneyAccountType::EWALLET->value => [],
        ];
        $accountCurrencies = [];
        $currencies = [];
        $accountIds = [];
        foreach ($accounts as $account) {
            /** @var MoneyAccount $account */
            $type = $account->getType()->value;
            $accountsByType[$type][] = ['id' => $account->getId(), 'name' => $account->getName()];
            $accountCurrencies[$account->getId()] = $account->getCurrency();
            if (!in_array($account->getCurrency(), $currencies, true)) {
                $currencies[] = $account->getCurrency();
            }
            $accountIds[] = $account->getId();
        }

        $balancesRaw = [];
        if ($accountIds) {
            $qb = $this->balanceRepository->createQueryBuilder('b')
                ->innerJoin('b.moneyAccount', 'a')
                ->addSelect('a')
                ->where('b.company = :company')
                ->andWhere('b.date BETWEEN :from AND :to')
                ->andWhere('a.id IN (:accountIds)')
                ->setParameter('company', $company)
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->setParameter('accountIds', $accountIds)
                ->orderBy('b.date', 'ASC')
                ->addOrderBy('a.name', 'ASC');
            $rows = $qb->getQuery()->getResult();
            foreach ($rows as $row) {
                /** @var \App\Entity\MoneyAccountDailyBalance $row */
                $dateKey = $row->getDate()->format('Y-m-d');
                $balancesRaw[$dateKey][$row->getMoneyAccount()->getId()] = $row->getClosingBalance();
            }
        }

        $dailyBalances = [];
        $prevValues = [];
        foreach ($accountIds as $id) {
            $prevValues[$id] = '0.00';
        }
        foreach ($dates as $date) {
            $dailyBalances[$date] = [];
            foreach ($accountIds as $id) {
                if (isset($balancesRaw[$date][$id])) {
                    $prevValues[$id] = $balancesRaw[$date][$id];
                }
                $dailyBalances[$date][$id] = $prevValues[$id];
            }
        }

        return $this->render('report/daily_balances.html.twig', [
            'company' => $company,
            'date_from' => $from,
            'date_to' => $to,
            'dates' => $dates,
            'accountsByType' => $accountsByType,
            'accountCurrencies' => $accountCurrencies,
            'currencies' => $currencies,
            'dailyBalances' => $dailyBalances,
        ]);
    }
}
