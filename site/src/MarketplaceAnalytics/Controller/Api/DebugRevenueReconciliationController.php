<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Временный debug-endpoint для диагностики расхождения выручки
 * (SUM(total_revenue) по marketplace_sales vs данные в ЛК Ozon).
 *
 * Маппинг терминологии Ozon → DB:
 *   amount          → marketplace_sales.total_revenue
 *   posting_number  → marketplace_sales.external_order_id
 *   accrual_date    → marketplace_sales.sale_date
 *
 * Использование:
 *   GET /api/marketplace-analytics/debug/revenue-reconciliation?period=2026-02
 */
#[Route(
    path: '/api/marketplace-analytics/debug/revenue-reconciliation',
    name: 'api_marketplace_analytics_debug_revenue_reconciliation',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugRevenueReconciliationController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $periodStr = (string) $request->query->get('period', '');

        if ($periodStr === '' || preg_match('/^\d{4}-\d{2}$/', $periodStr) !== 1) {
            return $this->json(['error' => 'period is required in Y-m format (e.g. 2026-02)'], 422);
        }

        try {
            $periodFrom = new \DateTimeImmutable($periodStr . '-01');
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid period value'], 422);
        }

        $periodTo     = $periodFrom->modify('last day of this month');
        $nextDay1     = $periodTo->modify('+1 day');
        $nextDay2     = $periodTo->modify('+2 days');
        $breakdownFrom = $periodTo->modify('-2 days');

        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        return new JsonResponse([
            'period' => [
                'value' => $periodStr,
                'from'  => $periodFrom->format('Y-m-d'),
                'to'    => $periodTo->format('Y-m-d'),
            ],
            'total_revenue'    => $this->fetchTotalRevenue($companyId, $periodFrom, $periodTo),
            'orphan_rows'      => $this->fetchOrphanRows($companyId, $periodFrom, $periodTo),
            'duplicates'       => $this->fetchDuplicates($companyId, $periodFrom, $periodTo),
            'daily_breakdown'  => $this->fetchDailyBreakdown($companyId, $breakdownFrom, $nextDay2),
            'raw_documents'    => $this->fetchRawDocuments($companyId, $periodFrom, $periodTo),
        ]);
    }

    /**
     * @return array{count: int, sum: string}
     */
    private function fetchTotalRevenue(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                          AS cnt,
                COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = 'ozon'
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            SQL,
            [
                'companyId'  => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo'   => $to->format('Y-m-d'),
            ],
        );

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'sum'   => (string) ($row['total'] ?? '0'),
        ];
    }

    /**
     * @return array{count: int, sum: string}
     */
    private function fetchOrphanRows(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                          AS cnt,
                COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id      = :companyId
              AND s.marketplace    = 'ozon'
              AND s.sale_date     >= :periodFrom
              AND s.sale_date     <= :periodTo
              AND s.raw_document_id IS NULL
            SQL,
            [
                'companyId'  => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo'   => $to->format('Y-m-d'),
            ],
        );

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'sum'   => (string) ($row['total'] ?? '0'),
        ];
    }

    /**
     * posting_number (external_order_id) с COUNT > 1 — первые 20 шт.
     *
     * @return list<array{posting_number: string, count: int, sum: string}>
     */
    private function fetchDuplicates(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.external_order_id               AS posting_number,
                COUNT(*)                          AS cnt,
                COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = 'ozon'
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            GROUP BY s.external_order_id
            HAVING COUNT(*) > 1
            ORDER BY COUNT(*) DESC, s.external_order_id
            LIMIT 20
            SQL,
            [
                'companyId'  => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo'   => $to->format('Y-m-d'),
            ],
        );

        return array_map(
            static fn (array $r): array => [
                'posting_number' => (string) $r['posting_number'],
                'count'          => (int) $r['cnt'],
                'sum'            => (string) ($r['total'] ?? '0'),
            ],
            $rows,
        );
    }

    /**
     * Дневная разбивка по sale_date (accrual_date) для выбранного диапазона.
     *
     * @return list<array{accrual_date: string, count: int, sum: string}>
     */
    private function fetchDailyBreakdown(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.sale_date::text                 AS accrual_date,
                COUNT(*)                          AS cnt,
                COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = 'ozon'
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            GROUP BY s.sale_date
            ORDER BY s.sale_date
            SQL,
            [
                'companyId'  => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo'   => $to->format('Y-m-d'),
            ],
        );

        return array_map(
            static fn (array $r): array => [
                'accrual_date' => (string) $r['accrual_date'],
                'count'        => (int) $r['cnt'],
                'sum'          => (string) ($r['total'] ?? '0'),
            ],
            $rows,
        );
    }

    /**
     * raw_document_id → количество строк marketplace_sales за период,
     * привязанных к этому документу.
     *
     * @return list<array{raw_document_id: string|null, count: int, sum: string}>
     */
    private function fetchRawDocuments(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.raw_document_id                 AS raw_document_id,
                COUNT(*)                          AS cnt,
                COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = 'ozon'
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            GROUP BY s.raw_document_id
            ORDER BY COUNT(*) DESC, s.raw_document_id NULLS LAST
            SQL,
            [
                'companyId'  => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo'   => $to->format('Y-m-d'),
            ],
        );

        return array_map(
            static fn (array $r): array => [
                'raw_document_id' => $r['raw_document_id'] !== null ? (string) $r['raw_document_id'] : null,
                'count'           => (int) $r['cnt'],
                'sum'             => (string) ($r['total'] ?? '0'),
            ],
            $rows,
        );
    }
}
