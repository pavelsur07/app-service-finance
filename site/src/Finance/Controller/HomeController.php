<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Report\Cashflow\CashflowReportBuilder;
use App\Report\Cashflow\CashflowReportParams;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\UiModeResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class HomeController extends AbstractController
{
    private const ACTIVITY_ALL = 'all';

    private const CASHFLOW_ACTIVITIES = [
        'operating' => 'Операционная',
        'financing' => 'Финансовая',
        'investing' => 'Инвестиционная',
        self::ACTIVITY_ALL => 'Общая',
    ];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyAccountDailyBalanceRepository $dailyBalanceRepository,
        private readonly CashflowReportBuilder $cashflowReportBuilder,
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly UiModeResolver $uiModeResolver,
    ) {
    }

    // «/» отдан HomeRedirectController: он выбирает лендинг по доступным модулям.
    // Финансовый дашборд живёт на своём роуте, чтобы не конкурировать с React-пилотом на /dashboard.
    #[Route('/finance', name: 'app_finance_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $cashCurrency = $this->resolveCashCurrency($request);
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'Выберите поддерживаемую валюту ДДС.');

            return $this->redirectToRoute('app_finance_index', ['currency' => FiatCurrency::RUB->value]);
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);

        $accounts = $this->moneyAccountRepository->findByFilters(
            $company,
            null,
            [$cashCurrency->value],
            true,
            null,
            ['name' => 'ASC'],
        );

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
        $cashflowActivity = $this->resolveCashflowActivity($request);
        [$inflow30, $outflow30] = $this->cashflowTotalsForActivity($report, $cashCurrency, $cashflowActivity);

        $template = UiModeResolver::APP === $this->uiModeResolver->current()
            ? 'app/home/index.html.twig'
            : 'home/index.html.twig';

        return $this->render($template, [
            'activeCompany' => $company,
            'cashCurrency' => $cashCurrency,
            'cashCurrencies' => FiatCurrency::cases(),
            'cashflowActivity' => $cashflowActivity,
            'cashflowActivities' => self::CASHFLOW_ACTIVITIES,
            'kpi' => [
                'todayBalance' => $todayBalance,
                'inflow30' => $inflow30,
                'outflow30' => $outflow30,
            ],
        ]);
    }

    #[Route('/dashboard', name: 'app_dashboard_index', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        try {
            $cashCurrency = $this->resolveCashCurrency($request);
        } catch (\InvalidArgumentException) {
            return $this->redirectToRoute('app_dashboard_index', ['currency' => FiatCurrency::RUB->value]);
        }

        return $this->render('home/dashboard.html.twig', ['cashCurrency' => $cashCurrency]);
    }

    private function resolveCashCurrency(Request $request): FiatCurrency
    {
        $currency = $request->query->get('currency');
        if (null === $currency) {
            return FiatCurrency::RUB;
        }
        if (!is_string($currency)) {
            throw new \InvalidArgumentException('Invalid cash currency.');
        }

        return FiatCurrency::fromCode($currency);
    }

    private function resolveCashflowActivity(Request $request): string
    {
        $activity = $request->query->get('activity');

        return is_string($activity) && array_key_exists($activity, self::CASHFLOW_ACTIVITIES)
            ? $activity
            : self::ACTIVITY_ALL;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array{float, float}
     */
    private function cashflowTotalsForActivity(array $report, FiatCurrency $cashCurrency, string $activity): array
    {
        $selectedFlowKind = match ($activity) {
            'operating' => CashflowFlowKind::OPERATING,
            'financing' => CashflowFlowKind::FINANCING,
            'investing' => CashflowFlowKind::INVESTING,
            default => null,
        };
        $inflow30 = 0.0;
        $outflow30 = 0.0;
        $categoryTotals = $report['categoryTotals'];
        $accumulate = static function (array $node, bool $insideUnallocated = false) use (
            &$accumulate,
            &$inflow30,
            &$outflow30,
            $cashCurrency,
            $categoryTotals,
            $selectedFlowKind,
        ): array {
            /** @var CashflowCategory $category */
            $category = $categoryTotals[$node['id']]['entity'];
            $insideUnallocated = $insideUnallocated || in_array($category->getSystemCode(), [
                CashflowCategory::CODE_UNALLOCATED,
                CashflowCategory::SYSTEM_UNALLOCATED,
            ], true);
            $childrenInflow = 0.0;
            $childrenOutflow = 0.0;

            foreach ($node['children'] ?? [] as $child) {
                [$childInflow, $childOutflow] = $accumulate($child, $insideUnallocated);
                $childrenInflow += $childInflow;
                $childrenOutflow += $childOutflow;
            }

            $nodeInflow = 0.0;
            $nodeOutflow = 0.0;
            foreach (($node['totals'] ?? [])[$cashCurrency->value] ?? [] as $amount) {
                if ($amount > 0) {
                    $nodeInflow += $amount;
                } elseif ($amount < 0) {
                    $nodeOutflow += abs($amount);
                }
            }

            $ownInflow = $nodeInflow - $childrenInflow;
            $ownOutflow = $nodeOutflow - $childrenOutflow;
            $flowKind = $category->getEffectiveFlowKind();
            $include = CashflowFlowKind::TECHNICAL !== $flowKind
                && (null === $selectedFlowKind || (!$insideUnallocated && $selectedFlowKind === $flowKind));

            if ($include) {
                if ($ownInflow > 0) {
                    $inflow30 += $ownInflow;
                }

                if ($ownOutflow > 0) {
                    $outflow30 += $ownOutflow;
                }
            }

            return [$nodeInflow, $nodeOutflow];
        };

        foreach ($report['tree'] as $node) {
            $accumulate($node);
        }

        return [$inflow30, $outflow30];
    }
}
