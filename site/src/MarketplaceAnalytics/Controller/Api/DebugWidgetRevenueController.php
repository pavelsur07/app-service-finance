<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Временный отладочный эндпоинт для сверки выручки виджета с Ozon «Балансом».
 *
 * Возвращает:
 *  - sales_total_revenue: SUM(total_revenue) из marketplace_sales
 *  - sales_count:         количество строк продаж за период
 *  - costs_with_sale_codes: агрегаты по marketplace_costs для категорий,
 *    в code/name которых встречается sale / commission / discount / bonus /
 *    partner / compensation — это потенциальные «Баллы за скидки»,
 *    «Программы партнёров» и т.п.
 *  - costs_total: общий срез по всем категориям (net_amount, costs, storno).
 *
 * Использование:
 *   GET /api/marketplace-analytics/debug/widget-revenue
 *       ?periodFrom=2026-04-01&periodTo=2026-04-13&marketplace=ozon
 */
#[Route(
    path: '/api/marketplace-analytics/debug/widget-revenue',
    name: 'api_marketplace_analytics_debug_widget_revenue',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugWidgetRevenueController extends AbstractController
{
    /** @var list<string> */
    private const SALE_KEYWORDS = [
        'sale',
        'commission',
        'discount',
        'bonus',
        'partner',
        'compensation',
        'cashback',
        'premium',
        'marketing',
        'review',
    ];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $marketplace = $request->query->get('marketplace');
        if ($marketplace === null || $marketplace === '') {
            $marketplace = null;
        } else {
            $validValues = array_map(
                static fn (MarketplaceType $t): string => $t->value,
                MarketplaceType::cases(),
            );
            if (!in_array($marketplace, $validValues, true)) {
                return $this->json([
                    'error' => 'Invalid marketplace. Allowed: ' . implode(', ', $validValues),
                ], 422);
            }
        }

        $periodFromStr = (string) $request->query->get('periodFrom', '');
        $periodToStr   = (string) $request->query->get('periodTo', '');

        if ($periodFromStr === '' || $periodToStr === '') {
            return $this->json(['error' => 'periodFrom and periodTo are required'], 422);
        }

        try {
            $periodFrom = new \DateTimeImmutable($periodFromStr);
            $periodTo   = new \DateTimeImmutable($periodToStr);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date format. Expected Y-m-d'], 422);
        }

        if ($periodFrom > $periodTo) {
            return $this->json(['error' => 'periodFrom must be <= periodTo'], 422);
        }

        $companyId = (string) $company->getId();

        $sales = $this->fetchSalesAggregate($companyId, $marketplace, $periodFrom, $periodTo);
        $saleCoded = $this->fetchCostsMatching($companyId, $marketplace, $periodFrom, $periodTo, self::SALE_KEYWORDS);
        $allCosts = $this->fetchCostsMatching($companyId, $marketplace, $periodFrom, $periodTo, null);

        return new JsonResponse([
            'period' => [
                'from'        => $periodFrom->format('Y-m-d'),
                'to'          => $periodTo->format('Y-m-d'),
                'marketplace' => $marketplace,
            ],
            'sales_total_revenue' => $sales['total_revenue'],
            'sales_count'         => $sales['count'],
            'sales_quantity_sum'  => $sales['quantity_sum'],
            'costs_with_sale_codes' => [
                'keywords'    => self::SALE_KEYWORDS,
                'total_net'   => $this->sumField($saleCoded, 'net_amount'),
                'total_costs' => $this->sumField($saleCoded, 'sum_costs'),
                'total_storno'=> $this->sumField($saleCoded, 'sum_storno'),
                'rows'        => $saleCoded,
            ],
            'costs_all_categories' => [
                'total_net'   => $this->sumField($allCosts, 'net_amount'),
                'total_costs' => $this->sumField($allCosts, 'sum_costs'),
                'total_storno'=> $this->sumField($allCosts, 'sum_storno'),
                'rows'        => $allCosts,
            ],
        ]);
    }

    /**
     * @return array{total_revenue: string, count: int, quantity_sum: int}
     */
    private function fetchSalesAggregate(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $mpFilter = $marketplace !== null ? 'AND s.marketplace = :marketplace' : '';

        $row = $this->connection->fetchAssociative(
            <<<SQL
            SELECT
                COALESCE(SUM(s.total_revenue), 0) AS total_revenue,
                COUNT(*)                          AS sales_count,
                COALESCE(SUM(s.quantity), 0)      AS quantity_sum
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.sale_date >= :periodFrom
              AND s.sale_date <= :periodTo
              {$mpFilter}
            SQL,
            array_filter([
                'companyId'   => $companyId,
                'periodFrom'  => $from->format('Y-m-d'),
                'periodTo'    => $to->format('Y-m-d'),
                'marketplace' => $marketplace,
            ], static fn ($v) => $v !== null),
        );

        return [
            'total_revenue' => (string) ($row['total_revenue'] ?? '0'),
            'count'         => (int) ($row['sales_count'] ?? 0),
            'quantity_sum'  => (int) ($row['quantity_sum'] ?? 0),
        ];
    }

    /**
     * @param list<string>|null $keywords null = без фильтра, возвращает все категории
     *
     * @return list<array{
     *     category_code: ?string,
     *     category_name: ?string,
     *     rows_count: int,
     *     sum_costs: string,
     *     sum_storno: string,
     *     net_amount: string,
     * }>
     */
    private function fetchCostsMatching(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $keywords,
    ): array {
        $params = [
            'companyId'  => $companyId,
            'periodFrom' => $from->format('Y-m-d'),
            'periodTo'   => $to->format('Y-m-d'),
        ];

        $mpFilter = '';
        if ($marketplace !== null) {
            $mpFilter = 'AND c.marketplace = :marketplace';
            $params['marketplace'] = $marketplace;
        }

        $keywordFilter = '';
        if ($keywords !== null && $keywords !== []) {
            $conditions = [];
            foreach ($keywords as $idx => $keyword) {
                $ph = 'kw' . $idx;
                $conditions[] = "(LOWER(cc.code) LIKE :{$ph} OR LOWER(cc.name) LIKE :{$ph})";
                $params[$ph] = '%' . strtolower($keyword) . '%';
            }
            $keywordFilter = 'AND (' . implode(' OR ', $conditions) . ')';
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                cc.code AS category_code,
                cc.name AS category_name,
                COUNT(*) AS rows_count,
                COALESCE(SUM(CASE
                    WHEN c.operation_type = 'storno' THEN 0
                    ELSE ABS(c.amount)
                END), 0) AS sum_costs,
                COALESCE(SUM(CASE
                    WHEN c.operation_type = 'storno' THEN ABS(c.amount)
                    ELSE 0
                END), 0) AS sum_storno,
                COALESCE(SUM(CASE
                    WHEN c.operation_type = 'storno' THEN -ABS(c.amount)
                    ELSE ABS(c.amount)
                END), 0) AS net_amount
            FROM marketplace_costs c
            LEFT JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id = :companyId
              AND c.cost_date >= :periodFrom
              AND c.cost_date <= :periodTo
              {$mpFilter}
              {$keywordFilter}
            GROUP BY cc.code, cc.name
            ORDER BY sum_costs DESC
            SQL,
            $params,
        );

        return array_map(
            static fn (array $r): array => [
                'category_code' => $r['category_code'] !== null ? (string) $r['category_code'] : null,
                'category_name' => $r['category_name'] !== null ? (string) $r['category_name'] : null,
                'rows_count'    => (int) $r['rows_count'],
                'sum_costs'     => (string) $r['sum_costs'],
                'sum_storno'    => (string) $r['sum_storno'],
                'net_amount'    => (string) $r['net_amount'],
            ],
            $rows,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function sumField(array $rows, string $field): string
    {
        $sum = '0';
        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) ($row[$field] ?? '0'), 2);
        }

        return $sum;
    }
}
