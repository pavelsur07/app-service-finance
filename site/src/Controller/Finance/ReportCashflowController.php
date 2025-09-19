<?php

namespace App\Controller\Finance;

use App\Enum\CashDirection;
use App\Repository\CashflowCategoryRepository;
use App\Repository\CashTransactionRepository;
use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/reports/cashflow')]
class ReportCashflowController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private CashflowCategoryRepository $categoryRepository,
        private CashTransactionRepository $transactionRepository,
        private MoneyAccountRepository $accountRepository,
        private MoneyAccountDailyBalanceRepository $balanceRepository,
    ) {
    }

    #[Route('', name: 'report_cashflow_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $payload = $this->buildCashflowPayload($request);

        return $this->render('report/cashflow.html.twig', $payload);
    }

    #[Route('/api/public/reports/cashflow.json', name: 'api_report_cashflow_json', methods: ['GET'])]
    public function apiJson(Request $request): Response
    {
        [$rows, $columns, $params] = $this->buildCashflowPayload($request);

        return $this->json([
            'meta'    => $params,
            'columns' => $columns,
            'rows'    => $rows,
        ]);
    }

    private function buildCashflowPayload(Request $request): array
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $group = $request->query->get('group', 'month');
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');
        $today = new \DateTimeImmutable('today');
        $currentQuarter = (int) floor(((int) $today->format('n') - 1) / 3);
        $quarterStartMonth = $currentQuarter * 3 + 1;
        $defaultFrom = new \DateTimeImmutable($today->format('Y').'-'.sprintf('%02d', $quarterStartMonth).'-01');
        $defaultTo = $defaultFrom->modify('+3 months -1 day');

        $from = $fromParam ? new \DateTimeImmutable($fromParam) : $defaultFrom;
        $to = $toParam ? new \DateTimeImmutable($toParam) : $defaultTo;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $periods = $this->buildPeriods($from, $to, $group);
        $periodCount = count($periods);

        $categories = $this->categoryRepository->findTreeByCompany($company);
        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat->getId()] = [
                'entity' => $cat,
                'totals' => [],
            ];
        }

        $rows = $this->transactionRepository->createQueryBuilder('t')
            ->select('IDENTITY(t.cashflowCategory) AS category', 't.direction', 't.amount', 't.currency', 't.occurredAt')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->getQuery()->getArrayResult();

        $companyTotals = [];
        foreach ($rows as $row) {
            $catId = $row['category'];
            if (!$catId || !isset($categoryMap[$catId])) {
                continue;
            }

            $amount = (float) $row['amount'];
            $direction = $row['direction'] instanceof CashDirection
                ? $row['direction']->value
                : $row['direction'];
            $amount = $direction === CashDirection::OUTFLOW->value
                ? -abs($amount)
                : abs($amount);
            $currency = $row['currency'];
            $periodIndex = $this->findPeriodIndex($periods, $row['occurredAt']);
            if (null === $periodIndex) {
                continue;
            }

            if (!isset($categoryMap[$catId]['totals'][$currency])) {
                $categoryMap[$catId]['totals'][$currency] = array_fill(0, $periodCount, 0.0);
            }

            $categoryMap[$catId]['totals'][$currency][$periodIndex] += $amount;
            $companyTotals[$currency][$periodIndex] = ($companyTotals[$currency][$periodIndex] ?? 0) + $amount;
        }

        foreach (array_reverse($categories) as $cat) {
            $parent = $cat->getParent();
            if ($parent && isset($categoryMap[$parent->getId()])) {
                $childTotals = $categoryMap[$cat->getId()]['totals'];
                foreach ($childTotals as $currency => $vals) {
                    if (!isset($categoryMap[$parent->getId()]['totals'][$currency])) {
                        $categoryMap[$parent->getId()]['totals'][$currency] = array_fill(0, $periodCount, 0.0);
                    }

                    foreach ($vals as $idx => $val) {
                        $categoryMap[$parent->getId()]['totals'][$currency][$idx] += $val;
                    }
                }
            }
        }

        $rootCategories = [];
        foreach ($categories as $cat) {
            if (!$cat->getParent()) {
                $rootCategories[] = $cat;
            }
        }

        $accounts = $this->accountRepository->findBy(['company' => $company]);
        $openingByCurrency = [];
        foreach ($accounts as $account) {
            $date = $from->setTime(0, 0);
            $snapshot = $this->balanceRepository->findOneBy([
                'company' => $company,
                'moneyAccount' => $account,
                'date' => $date,
            ]);

            if ($snapshot) {
                $opening = (float) $snapshot->getOpeningBalance();
            } else {
                $prev = $this->balanceRepository->findLastBefore($company, $account, $from);
                if ($prev) {
                    $opening = (float) $prev->getClosingBalance();
                } else {
                    $opening = (float) $account->getOpeningBalance();
                }
            }

            $currency = $account->getCurrency();
            $openingByCurrency[$currency] = ($openingByCurrency[$currency] ?? 0) + $opening;
        }

        $openings = [];
        $closings = [];
        $currencies = array_unique(array_merge(array_keys($openingByCurrency), array_keys($companyTotals)));
        foreach ($currencies as $currency) {
            $opening = $openingByCurrency[$currency] ?? 0.0;
            $openings[$currency] = [];
            $closings[$currency] = [];
            $current = $opening;
            for ($i = 0; $i < $periodCount; ++$i) {
                $openings[$currency][$i] = $current;
                $net = $companyTotals[$currency][$i] ?? 0;
                $current += $net;
                $closings[$currency][$i] = $current;
            }
        }

        return [
            'company' => $company,
            'group' => $group,
            'date_from' => $from,
            'date_to' => $to,
            'periods' => $periods,
            'categories' => $rootCategories,
            'categoryTotals' => $categoryMap,
            'openings' => $openings,
            'closings' => $closings,
        ];
    }

    private function buildPeriods(\DateTimeImmutable $from, \DateTimeImmutable $to, string $group): array
    {
        $periods = [];
        $current = $from;
        while ($current <= $to) {
            switch ($group) {
                case 'day':
                    $start = $current;
                    $end = $current;
                    $label = $current->format('d.m.Y');
                    $current = $current->modify('+1 day');
                    break;
                case 'week':
                    $start = $current;
                    $end = min($start->modify('+6 days'), $to);
                    $label = $start->format('d.m').'-'.$end->format('d.m');
                    $current = $end->modify('+1 day');
                    break;
                case 'quarter':
                    $startMonth = (int) $current->format('n');
                    $startMonth = (int) floor(($startMonth - 1) / 3) * 3 + 1;
                    $start = new \DateTimeImmutable($current->format('Y').'-'.sprintf('%02d', $startMonth).'-01');
                    $end = min($start->modify('+3 months -1 day'), $to);
                    $label = 'Q'.(((int) (($startMonth - 1) / 3)) + 1).' '.$start->format('Y');
                    $current = $end->modify('+1 day');
                    break;
                case 'year':
                    $start = new \DateTimeImmutable($current->format('Y-01-01'));
                    $end = min($start->modify('+1 year -1 day'), $to);
                    $label = $start->format('Y');
                    $current = $end->modify('+1 day');
                    break;
                case 'month':
                default:
                    $start = new \DateTimeImmutable($current->format('Y-m-01'));
                    $end = min($start->modify('+1 month -1 day'), $to);
                    $label = $start->format('m.Y');
                    $current = $end->modify('+1 day');
                    break;
            }
            $periods[] = ['label' => $label, 'start' => $start, 'end' => $end];
        }

        return $periods;
    }

    private function findPeriodIndex(array $periods, \DateTimeInterface $date): ?int
    {
        foreach ($periods as $idx => $p) {
            if ($date >= $p['start'] && $date <= $p['end']) {
                return $idx;
            }
        }

        return null;
    }
}
