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
 * Временный debug-endpoint для диагностики расхождений выручки и возвратов.
 *
 * GET  ?period=2026-02             — диагностика sales + returns + costs
 * GET  ?period=2026-02&cleanup=1   — preview: покажет дубли возвратов для удаления
 * POST ?period=2026-02&cleanup=1&confirm=1 — удалит дубли возвратов
 */
#[Route(
    path: '/api/marketplace-analytics/debug/revenue-reconciliation',
    name: 'api_marketplace_analytics_debug_revenue_reconciliation',
    methods: ['GET', 'POST'],
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

        if ($periodStr === '' || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodStr) !== 1) {
            return $this->json(['error' => 'period is required in Y-m format with month 01-12 (e.g. 2026-02)'], 422);
        }

        try {
            $periodFrom = new \DateTimeImmutable($periodStr . '-01');
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid period value'], 422);
        }

        $periodTo      = $periodFrom->modify('last day of this month');
        $breakdownFrom = $periodTo->modify('-2 days');
        $breakdownTo   = $periodTo->modify('+2 days');

        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $cleanup = (string) $request->query->get('cleanup', '0') === '1';
        $confirm = (string) $request->query->get('confirm', '0') === '1';

        // Cleanup mode: удаление дублей возвратов
        if ($cleanup && $confirm && $request->isMethod('POST')) {
            $deleted = $this->deleteReturnDuplicates($companyId, $periodFrom, $periodTo);
            return new JsonResponse([
                'action' => 'cleanup_executed',
                'period' => $periodStr,
                'deleted_return_duplicates' => $deleted,
                'returns_after' => $this->fetchReturnsTotal($companyId, $periodFrom, $periodTo),
            ]);
        }

        $result = [
            'period' => [
                'value' => $periodStr,
                'from'  => $periodFrom->format('Y-m-d'),
                'to'    => $periodTo->format('Y-m-d'),
            ],
            'total_revenue'    => $this->fetchTotalRevenue($companyId, $periodFrom, $periodTo),
            'orphan_rows'      => $this->fetchOrphanRows($companyId, $periodFrom, $periodTo),
            'duplicates'       => $this->fetchDuplicates($companyId, $periodFrom, $periodTo),
            'daily_breakdown'  => $this->fetchDailyBreakdown($companyId, $breakdownFrom, $breakdownTo),
            'raw_documents'    => $this->fetchRawDocuments($companyId, $periodFrom, $periodTo),
            // Новые секции
            'returns' => $this->fetchReturnsTotal($companyId, $periodFrom, $periodTo),
            'return_duplicates' => $this->fetchReturnDuplicates($companyId, $periodFrom, $periodTo),
            'returns_by_raw_document' => $this->fetchReturnsByRawDocument($companyId, $periodFrom, $periodTo),
            'costs_summary' => $this->fetchCostsSummary($companyId, $periodFrom, $periodTo),
        ];

        if ($cleanup) {
            $result['cleanup_preview'] = [
                'duplicates_to_delete' => $this->countReturnDuplicatesToDelete($companyId, $periodFrom, $periodTo),
                'hint' => 'POST with &confirm=1 to delete duplicates',
            ];
        }

        return new JsonResponse($result);
    }

    // ======================== SALES ========================

    private function fetchTotalRevenue(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS cnt, COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id = :companyId AND s.marketplace = 'ozon'
              AND s.sale_date >= :periodFrom AND s.sale_date <= :periodTo
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        return ['count' => (int) ($row['cnt'] ?? 0), 'sum' => (string) ($row['total'] ?? '0')];
    }

    private function fetchOrphanRows(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS cnt, COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id = :companyId AND s.marketplace = 'ozon'
              AND s.sale_date >= :periodFrom AND s.sale_date <= :periodTo
              AND s.raw_document_id IS NULL
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        return ['count' => (int) ($row['cnt'] ?? 0), 'sum' => (string) ($row['total'] ?? '0')];
    }

    private function fetchDuplicates(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT s.external_order_id AS posting_number, COUNT(*) AS cnt, COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id = :companyId AND s.marketplace = 'ozon'
              AND s.sale_date >= :periodFrom AND s.sale_date <= :periodTo
            GROUP BY s.external_order_id HAVING COUNT(*) > 1
            ORDER BY COUNT(*) DESC LIMIT 20
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        return array_map(fn($r) => [
            'posting_number' => (string) $r['posting_number'],
            'count' => (int) $r['cnt'],
            'sum' => (string) ($r['total'] ?? '0'),
        ], $rows);
    }

    private function fetchDailyBreakdown(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT s.sale_date::text AS accrual_date, COUNT(*) AS cnt, COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id = :companyId AND s.marketplace = 'ozon'
              AND s.sale_date >= :periodFrom AND s.sale_date <= :periodTo
            GROUP BY s.sale_date
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[(string) $r['accrual_date']] = ['count' => (int) $r['cnt'], 'sum' => (string) ($r['total'] ?? '0')];
        }
        $result = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $date = $cursor->format('Y-m-d');
            $result[] = ['accrual_date' => $date, 'count' => $byDate[$date]['count'] ?? 0, 'sum' => $byDate[$date]['sum'] ?? '0'];
            $cursor = $cursor->modify('+1 day');
        }
        return $result;
    }

    private function fetchRawDocuments(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT s.raw_document_id, COUNT(*) AS cnt, COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id = :companyId AND s.marketplace = 'ozon'
              AND s.sale_date >= :periodFrom AND s.sale_date <= :periodTo
            GROUP BY s.raw_document_id ORDER BY COUNT(*) DESC
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        return array_map(fn($r) => [
            'raw_document_id' => $r['raw_document_id'] !== null ? (string) $r['raw_document_id'] : null,
            'count' => (int) $r['cnt'],
            'sum' => (string) ($r['total'] ?? '0'),
        ], $rows);
    }

    // ======================== RETURNS ========================

    private function fetchReturnsTotal(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS cnt, COALESCE(SUM(r.total_revenue), 0) AS total
            FROM marketplace_returns r
            WHERE r.company_id = :companyId AND r.marketplace = 'ozon'
              AND r.return_date >= :periodFrom AND r.return_date <= :periodTo
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        return ['count' => (int) ($row['cnt'] ?? 0), 'sum' => (string) ($row['total'] ?? '0')];
    }

    /**
     * Возвраты с одинаковым external_order_id встречающиеся более 1 раза.
     */
    private function fetchReturnDuplicates(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT r.external_order_id AS posting_number, COUNT(*) AS cnt, COALESCE(SUM(r.total_revenue), 0) AS total
            FROM marketplace_returns r
            WHERE r.company_id = :companyId AND r.marketplace = 'ozon'
              AND r.return_date >= :periodFrom AND r.return_date <= :periodTo
            GROUP BY r.external_order_id HAVING COUNT(*) > 1
            ORDER BY COUNT(*) DESC LIMIT 30
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        return array_map(fn($r) => [
            'posting_number' => (string) $r['posting_number'],
            'count' => (int) $r['cnt'],
            'sum' => (string) ($r['total'] ?? '0'),
        ], $rows);
    }

    private function fetchReturnsByRawDocument(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT r.raw_document_id, COUNT(*) AS cnt, COALESCE(SUM(r.total_revenue), 0) AS total
            FROM marketplace_returns r
            WHERE r.company_id = :companyId AND r.marketplace = 'ozon'
              AND r.return_date >= :periodFrom AND r.return_date <= :periodTo
            GROUP BY r.raw_document_id ORDER BY COUNT(*) DESC
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        return array_map(fn($r) => [
            'raw_document_id' => $r['raw_document_id'] !== null ? (string) $r['raw_document_id'] : null,
            'count' => (int) $r['cnt'],
            'sum' => (string) ($r['total'] ?? '0'),
        ], $rows);
    }

    /**
     * Считает сколько дублей возвратов будет удалено.
     * Дубль = запись с тем же external_order_id, оставляем одну (с наименьшим id).
     */
    private function countReturnDuplicatesToDelete(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*) FROM marketplace_returns r
            WHERE r.company_id = :companyId AND r.marketplace = 'ozon'
              AND r.return_date >= :periodFrom AND r.return_date <= :periodTo
              AND r.id NOT IN (
                  SELECT MIN(r2.id) FROM marketplace_returns r2
                  WHERE r2.company_id = :companyId AND r2.marketplace = 'ozon'
                    AND r2.return_date >= :periodFrom AND r2.return_date <= :periodTo
                  GROUP BY r2.external_order_id
              )
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
    }

    /**
     * Удаляет дубли возвратов — оставляет одну запись (MIN(id)) для каждого external_order_id.
     */
    private function deleteReturnDuplicates(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_returns r
            WHERE r.company_id = :companyId AND r.marketplace = 'ozon'
              AND r.return_date >= :periodFrom AND r.return_date <= :periodTo
              AND r.id NOT IN (
                  SELECT MIN(r2.id) FROM marketplace_returns r2
                  WHERE r2.company_id = :companyId AND r2.marketplace = 'ozon'
                    AND r2.return_date >= :periodFrom AND r2.return_date <= :periodTo
                  GROUP BY r2.external_order_id
              )
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
    }

    // ======================== COSTS ========================

    private function fetchCostsSummary(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*) AS cnt,
                SUM(CASE WHEN c.raw_document_id IS NULL THEN 1 ELSE 0 END) AS orphan_count,
                SUM(CASE WHEN c.raw_document_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_count
            FROM marketplace_costs c
            WHERE c.company_id = :companyId AND c.marketplace = 'ozon'
              AND c.cost_date >= :periodFrom AND c.cost_date <= :periodTo
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $from->format('Y-m-d'), 'periodTo' => $to->format('Y-m-d')],
        );
        return [
            'total' => (int) ($row['cnt'] ?? 0),
            'orphan' => (int) ($row['orphan_count'] ?? 0),
            'linked' => (int) ($row['linked_count'] ?? 0),
        ];
    }
}
