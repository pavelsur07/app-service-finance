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

#[Route('/finance/reports/account-balances')]
class ReportAccountBalancesController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private MoneyAccountRepository $accountRepository,
        private MoneyAccountDailyBalanceRepository $balanceRepository,
    ) {
    }

    #[Route('', name: 'report_account_balances_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $dateParam = $request->query->get('date');
        $date = $dateParam ? (new \DateTimeImmutable($dateParam))->setTime(0, 0) : new \DateTimeImmutable('today');

        $accounts = $this->accountRepository->findBy(
            ['company' => $company, 'isActive' => true],
            ['type' => 'ASC', 'name' => 'ASC']
        );

        $accountsByCurrency = [];
        $accountIds = [];
        foreach ($accounts as $account) {
            /** @var MoneyAccount $account */
            $currency = $account->getCurrency();
            if (!isset($accountsByCurrency[$currency])) {
                $accountsByCurrency[$currency] = [
                    MoneyAccountType::BANK->value => [],
                    MoneyAccountType::CASH->value => [],
                    MoneyAccountType::EWALLET->value => [],
                ];
            }
            $accountsByCurrency[$currency][$account->getType()->value][] = $account;
            $accountIds[] = $account->getId();
        }

        $balancesByAccountId = [];
        if ($accountIds) {
            $qb = $this->balanceRepository->createQueryBuilder('b')
                ->innerJoin('b.moneyAccount', 'a')
                ->addSelect('a')
                ->where('b.company = :company')
                ->andWhere('b.date <= :date')
                ->andWhere('a.id IN (:accountIds)')
                ->setParameter('company', $company)
                ->setParameter('date', $date)
                ->setParameter('accountIds', $accountIds)
                ->orderBy('a.id', 'ASC')
                ->addOrderBy('b.date', 'DESC');

            $rows = $qb->getQuery()->getResult();
            foreach ($rows as $row) {
                /** @var \App\Entity\MoneyAccountDailyBalance $row */
                $accId = $row->getMoneyAccount()->getId();
                if (!isset($balancesByAccountId[$accId])) {
                    $balancesByAccountId[$accId] = $row->getClosingBalance();
                }
            }
        }

        $totalsByCurrency = [];
        foreach ($accountsByCurrency as $currency => $groups) {
            $totalsByType = [
                MoneyAccountType::BANK->value => '0.00',
                MoneyAccountType::CASH->value => '0.00',
                MoneyAccountType::EWALLET->value => '0.00',
            ];
            foreach ($groups as $type => $accs) {
                foreach ($accs as $acc) {
                    /** @var MoneyAccount $acc */
                    $balance = $balancesByAccountId[$acc->getId()] ?? '0.00';
                    $totalsByType[$type] = bcadd($totalsByType[$type], $balance, 2);
                }
            }
            $totalCompany = bcadd(
                bcadd($totalsByType[MoneyAccountType::BANK->value], $totalsByType[MoneyAccountType::CASH->value], 2),
                $totalsByType[MoneyAccountType::EWALLET->value],
                2
            );
            $totalsByCurrency[$currency] = [
                'totalsByType' => $totalsByType,
                'totalCompany' => $totalCompany,
            ];
        }

        return $this->render('report/account_balances.html.twig', [
            'company' => $company,
            'date' => $date,
            'accountsByCurrency' => $accountsByCurrency,
            'balancesByAccountId' => $balancesByAccountId,
            'totalsByCurrency' => $totalsByCurrency,
        ]);
    }
}
