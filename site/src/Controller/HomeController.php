<?php

declare(strict_types=1);

namespace App\Controller;

use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyAccountDailyBalanceRepository $dailyBalanceRepository,
        private readonly CashflowReportBuilder $cashflowReportBuilder,
        private readonly MoneyAccountRepository $moneyAccountRepository,
    ) {
    }

    #[Route('/', name: 'app_home_index')]
    public function index(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);

        $accounts = $this->moneyAccountRepository->findBy(['company' => $company]);

        $todayBalance = 0.0;
        foreach ($accounts as $account) {
            $snapshot = $this->dailyBalanceRepository->findOneBy([
                'company' => $company,
                'moneyAccount' => $account,
                'date' => $today,
            ]);

            if (null !== $snapshot) {
                $opening = (float) $snapshot->getOpeningBalance();
            } else {
                $previous = $this->dailyBalanceRepository->findLastBefore($company, $account, $today);
                if (null !== $previous) {
                    $opening = (float) $previous->getClosingBalance();
                } else {
                    $opening = (float) $account->getOpeningBalance();
                }
            }

            $todayBalance += $opening;
        }

        $from = $today->modify('-30 days');
        $params = new CashflowReportParams($company, 'day', $from, $today);
        $report = $this->cashflowReportBuilder->build($params);

        $inflow30 = 0.0;
        $outflow30 = 0.0;
        $accumulate = function (array $node) use (&$accumulate, &$inflow30, &$outflow30): array {
            $childrenInflow = 0.0;
            $childrenOutflow = 0.0;

            foreach ($node['children'] ?? [] as $child) {
                [$childInflow, $childOutflow] = $accumulate($child);
                $childrenInflow += $childInflow;
                $childrenOutflow += $childOutflow;
            }

            $nodeInflow = 0.0;
            $nodeOutflow = 0.0;
            foreach ($node['totals'] ?? [] as $values) {
                foreach ($values as $amount) {
                    if ($amount > 0) {
                        $nodeInflow += $amount;
                    } elseif ($amount < 0) {
                        $nodeOutflow += abs($amount);
                    }
                }
            }

            $ownInflow = $nodeInflow - $childrenInflow;
            $ownOutflow = $nodeOutflow - $childrenOutflow;

            if ($ownInflow > 0) {
                $inflow30 += $ownInflow;
            }

            if ($ownOutflow > 0) {
                $outflow30 += $ownOutflow;
            }

            return [$nodeInflow, $nodeOutflow];
        };

        foreach ($report['tree'] as $node) {
            $accumulate($node);
        }

        return $this->render('home/index.html.twig', [
            'kpi' => [
                'todayBalance' => $todayBalance,
                'inflow30' => $inflow30,
                'outflow30' => $outflow30,
            ],
        ]);
    }
}
