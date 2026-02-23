<?php

namespace App\Marketplace\Controller;

use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/analytics')]
#[IsGranted('ROLE_USER')]
class MarketplaceAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService
    ) {
    }

    #[Route('', name: 'marketplace_analytics_index')]
    public function index(Request $request): Response
    {
        // Получаем активную компанию
        $company = $this->activeCompanyService->getActiveCompany();

        // Получаем параметры фильтра
        $period = $request->query->get('period', 'month');
        $marketplace = $request->query->get('marketplace', 'all');
        $tab = $request->query->get('tab', 'summary');

        // ДЕМО ДАННЫЕ - KPI карточки
        $kpi = [
            'revenue' => 4_250_000,
            'margin' => 1_120_000,
            'units_sold' => 3_420,
            'roi' => 187,
            'return_rate' => 8.2,
            'turnover_days' => 42,
        ];

        // ДЕМО ДАННЫЕ - Сводный отчёт
        $summary = [
            'revenue_breakdown' => [
                'gmv' => 4_250_000,
                'commission' => -850_000,
                'logistics' => -620_000,
                'advertising' => -180_000,
                'returns' => -480_000,
                'contribution_margin' => 1_120_000,
            ],
            'dynamics_chart' => [
                'labels' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15'],
                'revenue' => [120000, 135000, 128000, 145000, 152000, 148000, 165000, 158000, 172000, 168000, 180000, 175000, 195000, 188000, 205000],
                'margin' => [35000, 42000, 38000, 45000, 48000, 44000, 52000, 49000, 54000, 52000, 58000, 55000, 62000, 59000, 65000],
                'expenses' => [85000, 93000, 90000, 100000, 104000, 104000, 113000, 109000, 118000, 116000, 122000, 120000, 133000, 129000, 140000],
            ],
            'expenses_by_type' => [
                'commission' => 850_000,
                'logistics' => 620_000,
                'advertising' => 180_000,
                'penalties' => 45_000,
            ],
        ];

        // ДЕМО ДАННЫЕ - По SKU
        $skuData = [
            [
                'sku' => 'ART-001-L',
                'sold' => 245,
                'revenue' => 980_000,
                'cogs' => 490_000,
                'commission' => 98_000,
                'logistics' => 73_500,
                'advertising' => 29_400,
                'cm_rub' => 289_100,
                'cm_percent' => 29.5,
                'turnover' => 28,
                'roi' => 220,
                'status' => 'good',
            ],
            [
                'sku' => 'ART-002-M',
                'sold' => 189,
                'revenue' => 567_000,
                'cogs' => 283_500,
                'commission' => 56_700,
                'logistics' => 42_525,
                'advertising' => 17_010,
                'cm_rub' => 167_265,
                'cm_percent' => 29.5,
                'turnover' => 35,
                'roi' => 185,
                'status' => 'good',
            ],
            [
                'sku' => 'ART-003-S',
                'sold' => 156,
                'revenue' => 312_000,
                'cogs' => 187_200,
                'commission' => 31_200,
                'logistics' => 23_400,
                'advertising' => 9_360,
                'cm_rub' => 60_840,
                'cm_percent' => 19.5,
                'turnover' => 42,
                'roi' => 145,
                'status' => 'warning',
            ],
            [
                'sku' => 'ART-004-XL',
                'sold' => 78,
                'revenue' => 234_000,
                'cogs' => 140_400,
                'commission' => 23_400,
                'logistics' => 17_550,
                'advertising' => 7_020,
                'cm_rub' => 45_630,
                'cm_percent' => 19.5,
                'turnover' => 55,
                'roi' => 132,
                'status' => 'warning',
            ],
            [
                'sku' => 'ART-005-M',
                'sold' => 45,
                'revenue' => 135_000,
                'cogs' => 81_000,
                'commission' => 13_500,
                'logistics' => 10_125,
                'advertising' => 4_050,
                'cm_rub' => 26_325,
                'cm_percent' => 19.5,
                'turnover' => 68,
                'roi' => 98,
                'status' => 'danger',
            ],
        ];

        // ДЕМО ДАННЫЕ - ABC анализ
        $abcData = [
            'summary' => [
                'a' => ['percent' => 70, 'count' => 12, 'profit' => 784_000],
                'b' => ['percent' => 20, 'count' => 28, 'profit' => 224_000],
                'c' => ['percent' => 10, 'count' => 85, 'profit' => 112_000],
            ],
            'items' => [
                ['sku' => 'ART-001-L', 'category' => 'A', 'profit_share' => 25.8, 'turnover' => 28],
                ['sku' => 'ART-002-M', 'category' => 'A', 'profit_share' => 14.9, 'turnover' => 35],
                ['sku' => 'ART-006-L', 'category' => 'A', 'profit_share' => 12.3, 'turnover' => 32],
                ['sku' => 'ART-003-S', 'category' => 'B', 'profit_share' => 5.4, 'turnover' => 42],
                ['sku' => 'ART-004-XL', 'category' => 'B', 'profit_share' => 4.1, 'turnover' => 55],
                ['sku' => 'ART-005-M', 'category' => 'C', 'profit_share' => 2.4, 'turnover' => 68],
            ],
        ];

        // ДЕМО ДАННЫЕ - Денежный поток
        $cashFlow = [
            'summary' => [
                'accrued' => 4_250_000,
                'withheld' => 2_130_000,
                'payable' => 2_120_000,
                'received' => 1_950_000,
                'difference' => 170_000,
            ],
            'chart' => [
                'labels' => ['Нед 1', 'Нед 2', 'Нед 3', 'Нед 4'],
                'accrued' => [1_100_000, 1_050_000, 1_200_000, 900_000],
                'paid' => [800_000, 950_000, 0, 200_000],
            ],
            'transactions' => [
                ['date' => '2026-02-15', 'type' => 'Выплата', 'amount' => 950_000, 'status' => 'completed'],
                ['date' => '2026-02-08', 'type' => 'Выплата', 'amount' => 800_000, 'status' => 'completed'],
                ['date' => '2026-02-22', 'type' => 'Ожидается', 'amount' => 1_170_000, 'status' => 'pending'],
            ],
        ];

        return $this->render('marketplace/analytics/index.html.twig', [
            'company' => $company,
            'period' => $period,
            'marketplace' => $marketplace,
            'active_tab_inner' => $tab,
            'kpi' => $kpi,
            'summary' => $summary,
            'sku_data' => $skuData,
            'abc_data' => $abcData,
            'cash_flow' => $cashFlow,
        ]);
    }
}
