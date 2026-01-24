<?php

namespace App\Controller\Finance;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Sahred\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/reports/account-balances-structured')]
class ReportAccountBalancesStructuredController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private MoneyAccountRepository $accountRepository,
        private AccountBalanceProvider $accountBalanceProvider,
        private CashTransactionRepository $cashTransactionRepository,
    ) {
    }

    #[Route('', name: 'report_account_balances_structured_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $defaultFrom = $today->modify('first day of this month')->setTime(0, 0);

        try {
            $from = $fromParam ? (new \DateTimeImmutable($fromParam))->setTime(0, 0) : $defaultFrom;
        } catch (\Throwable) {
            $from = $defaultFrom;
        }

        try {
            $to = $toParam ? (new \DateTimeImmutable($toParam))->setTime(0, 0) : $today;
        } catch (\Throwable) {
            $to = $today;
        }

        $accounts = $this->accountRepository->findBy(
            ['company' => $company, 'isActive' => true],
            ['type' => 'ASC', 'name' => 'ASC']
        );

        $accountIds = array_map(static fn (MoneyAccount $account) => $account->getId(), $accounts);

        $openingByAccountId = [];
        $closingByAccountId = [];
        $turnoversByAccountId = [];
        if (!empty($accountIds)) {
            $openingByAccountId = $this->accountBalanceProvider->getClosingBalancesUpToDate($company, $from, $accountIds);
            $closingByAccountId = $this->accountBalanceProvider->getClosingBalancesUpToDate($company, $to, $accountIds);
            $turnoversByAccountId = $this->cashTransactionRepository->sumByAccountAndPeriod($company, $accountIds, $from, $to);
        }

        $rowsByCurrency = [];

        foreach ($accounts as $account) {
            /** @var MoneyAccount $account */
            $accId = $account->getId();
            $currency = $account->getCurrency();

            $opening = $openingByAccountId[$accId] ?? '0.00';
            $closing = $closingByAccountId[$accId] ?? '0.00';
            $inflow = $turnoversByAccountId[$accId]['inflow'] ?? '0.00';
            $outflow = $turnoversByAccountId[$accId]['outflow'] ?? '0.00';

            if (!isset($rowsByCurrency[$currency])) {
                $rowsByCurrency[$currency] = [
                    'total' => [
                        'opening' => '0.00',
                        'inflow' => '0.00',
                        'outflow' => '0.00',
                        'closing' => '0.00',
                    ],
                    'accounts' => [],
                ];
            }

            $rowsByCurrency[$currency]['accounts'][] = [
                'id' => $account->getId(),
                'name' => $account->getName(),
                'type' => $account->getType()->value,
                'currency' => $currency,
                'opening' => $opening,
                'inflow' => $inflow,
                'outflow' => $outflow,
                'closing' => $closing,
            ];

            $rowsByCurrency[$currency]['total']['opening'] = bcadd($rowsByCurrency[$currency]['total']['opening'], $opening, 2);
            $rowsByCurrency[$currency]['total']['inflow'] = bcadd($rowsByCurrency[$currency]['total']['inflow'], $inflow, 2);
            $rowsByCurrency[$currency]['total']['outflow'] = bcadd($rowsByCurrency[$currency]['total']['outflow'], $outflow, 2);
            $rowsByCurrency[$currency]['total']['closing'] = bcadd($rowsByCurrency[$currency]['total']['closing'], $closing, 2);
        }

        return $this->render('report/account_balances_structured.html.twig', [
            'company' => $company,
            'from' => $from,
            'to' => $to,
            'rowsByCurrency' => $rowsByCurrency,
        ]);
    }
}
