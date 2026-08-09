<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Cash\Enum\FiatCurrency;
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
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyAccountDailyBalanceRepository $dailyBalanceRepository,
        private readonly CashflowReportBuilder $cashflowReportBuilder,
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly UiModeResolver $uiModeResolver,
    ) {
    }

    #[Route('/', name: 'app_home_index')]
    public function index(Request $request): Response
    {
        try {
            $cashCurrency = $this->resolveCashCurrency($request);
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'Выберите поддерживаемую валюту ДДС.');

            return $this->redirectToRoute('app_home_index', ['currency' => FiatCurrency::RUB->value]);
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

        $inflow30 = 0.0;
        $outflow30 = 0.0;
        $accumulate = static function (array $node) use (&$accumulate, &$inflow30, &$outflow30, $cashCurrency): array {
            $childrenInflow = 0.0;
            $childrenOutflow = 0.0;

            foreach ($node['children'] ?? [] as $child) {
                [$childInflow, $childOutflow] = $accumulate($child);
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

        $template = UiModeResolver::APP === $this->uiModeResolver->current()
            ? 'app/home/index.html.twig'
            : 'home/index.html.twig';

        return $this->render($template, [
            'activeCompany' => $company,
            'cashCurrency' => $cashCurrency,
            'cashCurrencies' => FiatCurrency::cases(),
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
}
