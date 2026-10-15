<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Cash\Application\DTO\MoneyAccountSummary;
use App\Cash\Facade\CashFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/reports/account-balances-structured')]
class ReportAccountBalancesStructuredController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private CashFacade $cashFacade,
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

        $recalcFrom = $company->getFinanceLockBefore()
            ?? $to->setDate((int) $to->format('Y'), 1, 1);

        $companyId = (string) $company->getId();
        $accounts = $this->cashFacade->listMoneyAccounts($companyId, activeOnly: true, typeFirst: true);

        $accountIds = array_map(static fn (MoneyAccountSummary $account): string => $account->id, $accounts);

        $openingByAccountId = [];
        $closingByAccountId = [];
        $turnoversByAccountId = [];
        if (!empty($accountIds)) {
            $openingByAccountId = $this->cashFacade->getOpeningBalancesUpToDate($companyId, $from, $accountIds);
            $closingByAccountId = $this->cashFacade->getClosingBalancesUpToDate($companyId, $to, $accountIds);
            $turnoversByAccountId = $this->cashFacade->sumTurnoversByAccount($companyId, $accountIds, $from, $to);
        }

        $rowsByCurrency = [];

        foreach ($accounts as $account) {
            $accId = $account->id;
            $currency = $account->currency;

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
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
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
            'recalcFrom' => $recalcFrom,
            'rowsByCurrency' => $rowsByCurrency,
        ]);
    }
}
