<?php

declare(strict_types=1);

namespace App\Controller;

use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Service\ActiveCompanyService;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyAccountDailyBalanceRepository $dailyBalanceRepository,
        private readonly CashflowReportBuilder $cashflowReportBuilder,
    ) {
    }

    #[Route('/', name: 'app_home_index')]
    public function index(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);

        $todayBalance = (float) $this->dailyBalanceRepository->createQueryBuilder('b')
            ->select('COALESCE(SUM(b.closingBalance), 0) as totalClosing')
            ->where('b.company = :company')
            ->andWhere('b.date = :date')
            ->setParameter('company', $company)
            ->setParameter('date', $today, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

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
