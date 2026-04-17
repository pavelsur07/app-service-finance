<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @internal Debug controller, to be removed
 */
#[Route(
    path: '/api/debug/sales-breakdown',
    name: 'api_debug_sales_breakdown',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugSalesBreakdownController extends AbstractController
{
    private const DETAIL_LIMIT = 1000;

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace = $request->query->get('marketplace');
        if ($marketplace !== 'ozon') {
            return $this->json(['error' => 'Only marketplace=ozon is supported'], 422);
        }

        $fromStr = (string) $request->query->get('from', '');
        $toStr = (string) $request->query->get('to', '');

        if ($fromStr === '' || $toStr === '') {
            return $this->json(['error' => 'from and to are required (YYYY-MM-DD)'], 422);
        }

        try {
            $from = new \DateTimeImmutable($fromStr);
            $to = new \DateTimeImmutable($toStr);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date format. Expected YYYY-MM-DD'], 422);
        }

        if ($from > $to) {
            return $this->json(['error' => 'from must be <= to'], 422);
        }

        $format = $request->query->get('format', 'summary');
        $table = $request->query->get('table');

        return match ($format) {
            'summary' => $this->handleSummary($companyId, $marketplace, $from, $to),
            'detail' => $this->handleDetail($companyId, $marketplace, $from, $to),
            'csv' => $this->handleCsv($companyId, $marketplace, $from, $to, $table),
            default => $this->json(['error' => 'Invalid format. Allowed: summary, detail, csv'], 422),
        };
    }

    private function handleSummary(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): JsonResponse {
        $sales = $this->fetchSalesSummary($companyId, $marketplace, $from, $to);
        $returns = $this->fetchReturnsSummary($companyId, $marketplace, $from, $to);
        $costs = $this->fetchCostsSummary($companyId, $marketplace, $from, $to);

        return new JsonResponse([
            'mode' => 'summary',
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'sales' => $sales,
            'returns' => $returns,
            'costs' => $costs,
        ]);
    }

    private function handleDetail(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): JsonResponse {
        $params = [
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'dateFrom' => $from->format('Y-m-d'),
            'dateTo' => $to->format('Y-m-d'),
        ];

        $salesRows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT s.id, s.sale_date, s.external_order_id, s.total_revenue, s.cost_price, s.raw_document_id
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date >= :dateFrom
              AND s.sale_date <= :dateTo
            ORDER BY s.sale_date, s.external_order_id
            LIMIT :lim
            SQL,
            [...$params, 'lim' => self::DETAIL_LIMIT + 1],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $salesTruncated = \count($salesRows) > self::DETAIL_LIMIT;
        if ($salesTruncated) {
            array_pop($salesRows);
        }

        $returnsRows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT r.id, r.return_date, r.external_return_id, r.refund_amount, r.cost_price, r.raw_document_id
            FROM marketplace_returns r
            WHERE r.company_id = :companyId
              AND r.marketplace = :marketplace
              AND r.return_date >= :dateFrom
              AND r.return_date <= :dateTo
            ORDER BY r.return_date, r.external_return_id
            LIMIT :lim
            SQL,
            [...$params, 'lim' => self::DETAIL_LIMIT + 1],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $returnsTruncated = \count($returnsRows) > self::DETAIL_LIMIT;
        if ($returnsTruncated) {
            array_pop($returnsRows);
        }

        $costsRows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT c.id, c.cost_date, c.external_id, c.amount, c.operation_type, c.raw_document_id
            FROM marketplace_costs c
            WHERE c.company_id = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date >= :dateFrom
              AND c.cost_date <= :dateTo
            ORDER BY c.cost_date, c.external_id
            LIMIT :lim
            SQL,
            [...$params, 'lim' => self::DETAIL_LIMIT + 1],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $costsTruncated = \count($costsRows) > self::DETAIL_LIMIT;
        if ($costsTruncated) {
            array_pop($costsRows);
        }

        $result = [
            'mode' => 'detail',
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'sales' => $salesRows,
            'returns' => $returnsRows,
            'costs' => $costsRows,
        ];

        if ($salesTruncated) {
            $result['sales_truncated'] = true;
        }
        if ($returnsTruncated) {
            $result['returns_truncated'] = true;
        }
        if ($costsTruncated) {
            $result['costs_truncated'] = true;
        }

        return new JsonResponse($result);
    }

    private function handleCsv(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $table,
    ): JsonResponse|StreamedResponse {
        $allowedTables = ['sales', 'returns', 'costs'];
        if ($table === null || !in_array($table, $allowedTables, true)) {
            return $this->json([
                'error' => 'table parameter is required for csv format. Allowed: ' . implode(', ', $allowedTables),
            ], 422);
        }

        $params = [
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'dateFrom' => $from->format('Y-m-d'),
            'dateTo' => $to->format('Y-m-d'),
        ];

        $filename = sprintf('debug_%s_%s_%s_%s.csv', $table, $marketplace, $from->format('Y-m-d'), $to->format('Y-m-d'));

        return match ($table) {
            'sales' => $this->streamCsv(
                $filename,
                ['id', 'sale_date', 'external_order_id', 'total_revenue', 'cost_price', 'raw_document_id'],
                <<<SQL
                SELECT s.id, s.sale_date, s.external_order_id, s.total_revenue, s.cost_price, s.raw_document_id
                FROM marketplace_sales s
                WHERE s.company_id = :companyId
                  AND s.marketplace = :marketplace
                  AND s.sale_date >= :dateFrom
                  AND s.sale_date <= :dateTo
                ORDER BY s.sale_date, s.external_order_id
                SQL,
                $params,
            ),
            'returns' => $this->streamCsv(
                $filename,
                ['id', 'return_date', 'external_return_id', 'refund_amount', 'cost_price', 'raw_document_id'],
                <<<SQL
                SELECT r.id, r.return_date, r.external_return_id, r.refund_amount, r.cost_price, r.raw_document_id
                FROM marketplace_returns r
                WHERE r.company_id = :companyId
                  AND r.marketplace = :marketplace
                  AND r.return_date >= :dateFrom
                  AND r.return_date <= :dateTo
                ORDER BY r.return_date, r.external_return_id
                SQL,
                $params,
            ),
            'costs' => $this->streamCsv(
                $filename,
                ['id', 'cost_date', 'external_id', 'amount', 'operation_type', 'raw_document_id'],
                <<<SQL
                SELECT c.id, c.cost_date, c.external_id, c.amount, c.operation_type, c.raw_document_id
                FROM marketplace_costs c
                WHERE c.company_id = :companyId
                  AND c.marketplace = :marketplace
                  AND c.cost_date >= :dateFrom
                  AND c.cost_date <= :dateTo
                ORDER BY c.cost_date, c.external_id
                SQL,
                $params,
            ),
        };
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed> $params
     */
    private function streamCsv(string $filename, array $headers, string $sql, array $params): StreamedResponse
    {
        $connection = $this->connection;

        $response = new StreamedResponse(static function () use ($connection, $headers, $sql, $params): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');

            $result = $connection->executeQuery($sql, $params);

            while (($row = $result->fetchAssociative()) !== false) {
                fputcsv($out, array_values($row), ',', '"', '');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * @return array{total_records: int, total_revenue: string, breakdown_by_external_id_pattern: array<string, array{count: int, sum: string}>, sum_by_month: array<string, string>, zero_amount_records: int, negative_revenue_records: int}
     */
    private function fetchSalesSummary(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $params = [
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'dateFrom' => $from->format('Y-m-d'),
            'dateTo' => $to->format('Y-m-d'),
        ];

        $totals = $this->connection->fetchAssociative(
            <<<SQL
            SELECT
                COUNT(*) AS total_records,
                COALESCE(SUM(s.total_revenue), 0) AS total_revenue,
                COUNT(*) FILTER (WHERE s.total_revenue = 0) AS zero_amount_records,
                COUNT(*) FILTER (WHERE s.total_revenue < 0) AS negative_revenue_records
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date >= :dateFrom
              AND s.sale_date <= :dateTo
            SQL,
            $params,
        );

        $breakdown = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                CASE
                    WHEN s.external_order_id LIKE '%\_storno' THEN 'storno'
                    ELSE 'regular'
                END AS pattern,
                COUNT(*) AS cnt,
                COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date >= :dateFrom
              AND s.sale_date <= :dateTo
            GROUP BY pattern
            SQL,
            $params,
        );

        $byPattern = [];
        foreach ($breakdown as $row) {
            $byPattern[$row['pattern']] = [
                'count' => (int) $row['cnt'],
                'sum' => (string) $row['total'],
            ];
        }

        $byMonth = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                TO_CHAR(s.sale_date, 'YYYY-MM') AS month,
                COALESCE(SUM(s.total_revenue), 0) AS total
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date >= :dateFrom
              AND s.sale_date <= :dateTo
            GROUP BY month
            ORDER BY month
            SQL,
            $params,
        );

        $sumByMonth = [];
        foreach ($byMonth as $row) {
            $sumByMonth[$row['month']] = (string) $row['total'];
        }

        return [
            'total_records' => (int) $totals['total_records'],
            'total_revenue' => (string) $totals['total_revenue'],
            'breakdown_by_external_id_pattern' => $byPattern,
            'sum_by_month' => $sumByMonth,
            'zero_amount_records' => (int) $totals['zero_amount_records'],
            'negative_revenue_records' => (int) $totals['negative_revenue_records'],
        ];
    }

    /**
     * @return array{total_records: int, total_refund: string, breakdown_by_external_id_pattern: array<string, array{count: int, sum: string}>}
     */
    private function fetchReturnsSummary(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $params = [
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'dateFrom' => $from->format('Y-m-d'),
            'dateTo' => $to->format('Y-m-d'),
        ];

        $totals = $this->connection->fetchAssociative(
            <<<SQL
            SELECT
                COUNT(*) AS total_records,
                COALESCE(SUM(r.refund_amount), 0) AS total_refund
            FROM marketplace_returns r
            WHERE r.company_id = :companyId
              AND r.marketplace = :marketplace
              AND r.return_date >= :dateFrom
              AND r.return_date <= :dateTo
            SQL,
            $params,
        );

        $breakdown = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                CASE
                    WHEN r.external_return_id LIKE '%\_storno' THEN 'storno'
                    ELSE 'regular'
                END AS pattern,
                COUNT(*) AS cnt,
                COALESCE(SUM(r.refund_amount), 0) AS total
            FROM marketplace_returns r
            WHERE r.company_id = :companyId
              AND r.marketplace = :marketplace
              AND r.return_date >= :dateFrom
              AND r.return_date <= :dateTo
            GROUP BY pattern
            SQL,
            $params,
        );

        $byPattern = [];
        foreach ($breakdown as $row) {
            $byPattern[$row['pattern']] = [
                'count' => (int) $row['cnt'],
                'sum' => (string) $row['total'],
            ];
        }

        return [
            'total_records' => (int) $totals['total_records'],
            'total_refund' => (string) $totals['total_refund'],
            'breakdown_by_external_id_pattern' => $byPattern,
        ];
    }

    /**
     * @return array{total_records: int, total_amount: string, breakdown_by_suffix: array<string, array{count: int, sum: string}>}
     */
    private function fetchCostsSummary(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $params = [
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'dateFrom' => $from->format('Y-m-d'),
            'dateTo' => $to->format('Y-m-d'),
        ];

        $totals = $this->connection->fetchAssociative(
            <<<SQL
            SELECT
                COUNT(*) AS total_records,
                COALESCE(SUM(c.amount), 0) AS total_amount
            FROM marketplace_costs c
            WHERE c.company_id = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date >= :dateFrom
              AND c.cost_date <= :dateTo
            SQL,
            $params,
        );

        $breakdown = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                CASE
                    WHEN c.external_id LIKE '%\_commission\_return' THEN '_commission_return'
                    WHEN c.external_id LIKE '%\_return\_delivery' THEN '_return_delivery'
                    WHEN c.external_id LIKE '%\_commission' THEN '_commission'
                    WHEN c.external_id LIKE '%\_delivery' THEN '_delivery'
                    WHEN c.external_id LIKE '%\_main' THEN '_main'
                    WHEN c.external_id ~ '_svc_\d+(_item_\d+)?$' THEN '_svc_*'
                    ELSE 'other'
                END AS suffix_bucket,
                COUNT(*) AS cnt,
                COALESCE(SUM(c.amount), 0) AS total
            FROM marketplace_costs c
            WHERE c.company_id = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date >= :dateFrom
              AND c.cost_date <= :dateTo
            GROUP BY suffix_bucket
            ORDER BY suffix_bucket
            SQL,
            $params,
        );

        $bySuffix = [];
        foreach ($breakdown as $row) {
            $bySuffix[$row['suffix_bucket']] = [
                'count' => (int) $row['cnt'],
                'sum' => (string) $row['total'],
            ];
        }

        return [
            'total_records' => (int) $totals['total_records'],
            'total_amount' => (string) $totals['total_amount'],
            'breakdown_by_suffix' => $bySuffix,
        ];
    }
}
