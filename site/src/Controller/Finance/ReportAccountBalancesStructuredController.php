<?php

namespace App\Controller\Finance;

use App\Entity\MoneyAccount;
use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use App\Service\AccountBalanceProvider;
use Doctrine\DBAL\Types\Types;
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
        private MoneyAccountDailyBalanceRepository $balanceRepository,
        private AccountBalanceProvider $accountBalanceProvider,
    ) {
    }

    #[Route('', name: 'report_account_balances_structured_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        try {
            $from = $fromParam ? (new \DateTimeImmutable($fromParam))->setTime(0, 0) : (new \DateTimeImmutable('today'))->setTime(0, 0);
        } catch (\Throwable) {
            $from = (new \DateTimeImmutable('today'))->setTime(0, 0);
        }

        try {
            $to = $toParam ? (new \DateTimeImmutable($toParam))->setTime(0, 0) : (new \DateTimeImmutable('today'))->setTime(0, 0);
        } catch (\Throwable) {
            $to = (new \DateTimeImmutable('today'))->setTime(0, 0);
        }

        $selectedAccountIds = array_map('intval', $request->query->all('accounts'));
        $excludeSelected = $request->query->getBoolean('exclude');
        $hideZero = $request->query->getBoolean('hideZero');

        $accounts = $this->accountRepository->findBy(
            ['company' => $company, 'isActive' => true],
            ['type' => 'ASC', 'name' => 'ASC']
        );

        $filteredAccounts = array_filter(
            $accounts,
            static function (MoneyAccount $account) use ($selectedAccountIds, $excludeSelected): bool {
                if (empty($selectedAccountIds)) {
                    return true;
                }

                $isSelected = in_array($account->getId(), $selectedAccountIds, true);

                return $excludeSelected ? !$isSelected : $isSelected;
            }
        );

        $accountIds = array_map(static fn (MoneyAccount $account) => $account->getId(), $filteredAccounts);

        $openingByAccountId = [];
        $closingByAccountId = [];
        $flowByAccountId = [];

        if (!empty($accountIds)) {
            $openingByAccountId = $this->accountBalanceProvider->getClosingBalancesUpToDate($company, $from, $accountIds);
            $closingByAccountId = $this->accountBalanceProvider->getClosingBalancesUpToDate($company, $to, $accountIds);

            $flowRows = $this->balanceRepository->createQueryBuilder('b')
                ->select('IDENTITY(b.moneyAccount) AS accountId')
                ->addSelect('COALESCE(SUM(b.inflow), 0) AS inflowTotal')
                ->addSelect('COALESCE(SUM(b.outflow), 0) AS outflowTotal')
                ->where('b.company = :company')
                ->andWhere('b.date BETWEEN :fromDate AND :toDate')
                ->andWhere('b.moneyAccount IN (:accountIds)')
                ->setParameter('company', $company)
                ->setParameter('fromDate', $from, Types::DATE_IMMUTABLE)
                ->setParameter('toDate', $to, Types::DATE_IMMUTABLE)
                ->setParameter('accountIds', $accountIds)
                ->groupBy('b.moneyAccount')
                ->getQuery()
                ->getArrayResult();

            foreach ($flowRows as $row) {
                $flowByAccountId[(int) $row['accountId']] = [
                    'inflow' => (string) $row['inflowTotal'],
                    'outflow' => (string) $row['outflowTotal'],
                ];
            }
        }

        $rowsByCurrency = [];

        foreach ($filteredAccounts as $account) {
            /** @var MoneyAccount $account */
            $accId = $account->getId();
            $currency = $account->getCurrency();

            $opening = $openingByAccountId[$accId] ?? '0.00';
            $closing = $closingByAccountId[$accId] ?? '0.00';
            $inflow = $flowByAccountId[$accId]['inflow'] ?? '0.00';
            $outflow = $flowByAccountId[$accId]['outflow'] ?? '0.00';

            $isZeroRow = 0 === bccomp($opening, '0', 2)
                && 0 === bccomp($inflow, '0', 2)
                && 0 === bccomp($outflow, '0', 2)
                && 0 === bccomp($closing, '0', 2);

            if ($hideZero && $isZeroRow) {
                continue;
            }

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
                'account' => $account,
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
            'accountsForFilter' => $accounts,
            'selectedAccountIds' => $selectedAccountIds,
            'excludeSelected' => $excludeSelected,
            'hideZero' => $hideZero,
            'rowsByCurrency' => $rowsByCurrency,
        ]);
    }
}
