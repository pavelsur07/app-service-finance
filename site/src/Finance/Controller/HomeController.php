<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Cash\Enum\FiatCurrency;
use App\Finance\Application\Service\FinanceDashboardKpiProvider;
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
        private readonly FinanceDashboardKpiProvider $kpiProvider,
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
        $uiMode = $this->uiModeResolver->current();
        $cashflowActivity = $this->resolveCashflowActivity($request);
        $dashboardKpis = $this->kpiProvider->build(
            $company,
            $cashCurrency,
            $cashflowActivity,
            withComparisons: true,
            today: $today,
        );
        $kpiPeriodLabels = $this->kpiPeriodLabels($dashboardKpis['periods']);
        $cashflowReconciliationQuery = [
            'from' => $dashboardKpis['periods']['current']['from']->format('Y-m-d'),
            'to' => $dashboardKpis['periods']['current']['to']->format('Y-m-d'),
            'group' => 'month',
            'reconcile' => 'dashboard',
            'activity' => $cashflowActivity,
            'currency' => $cashCurrency->value,
        ];

        $template = UiModeResolver::APP === $uiMode
            ? 'app/home/index.html.twig'
            : 'home/index.html.twig';

        return $this->render($template, [
            'activeCompany' => $company,
            'cashCurrency' => $cashCurrency,
            'cashCurrencies' => FiatCurrency::cases(),
            'cashflowActivity' => $cashflowActivity,
            'cashflowActivities' => self::CASHFLOW_ACTIVITIES,
            'kpi' => $dashboardKpis['kpi'],
            'kpiComparisons' => $dashboardKpis['comparisons'],
            'kpiPeriodLabels' => $kpiPeriodLabels,
            'cashflowReconciliationQuery' => $cashflowReconciliationQuery,
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
     * @param array{
     *     current:array{from:\DateTimeImmutable,to:\DateTimeImmutable},
     *     previous:array{from:\DateTimeImmutable,to:\DateTimeImmutable},
     *     balanceComparisonDate:\DateTimeImmutable
     * } $periods
     *
     * @return array{current:string,previous:string,balanceComparison:string}
     */
    private function kpiPeriodLabels(array $periods): array
    {
        $currentTo = $periods['current']['to'];
        $balanceComparisonDate = $periods['balanceComparisonDate'];

        return [
            'current' => $this->dateRangeLabel($periods['current']['from'], $currentTo),
            'previous' => $this->dateRangeLabel($periods['previous']['from'], $periods['previous']['to']),
            'balanceComparison' => 'На '.$balanceComparisonDate->format(
                $balanceComparisonDate->format('Y') === $currentTo->format('Y') ? 'd.m' : 'd.m.Y',
            ),
        ];
    }

    private function dateRangeLabel(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $format = $from->format('Y') === $to->format('Y') ? 'd.m' : 'd.m.Y';

        return $from->format($format).'–'.$to->format($format);
    }
}
