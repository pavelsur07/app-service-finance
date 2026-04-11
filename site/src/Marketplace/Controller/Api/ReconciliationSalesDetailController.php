<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TEMPORARY — удалить после диагностики расхождения ~2 402 ₽ в продажах.
 *
 * Построчная диагностика: дубликаты, аномальные суммы, группировка по external_order_id.
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationSalesDetailController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        '/api/marketplace/reconciliation/debug/sales-detail',
        name: 'api_marketplace_reconciliation_debug_sales_detail',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $payload     = json_decode($request->getContent(), true) ?? [];
        $periodFrom  = $payload['periodFrom'] ?? '';
        $periodTo    = $payload['periodTo'] ?? '';
        $marketplace = $payload['marketplace'] ?? 'ozon';

        if ($periodFrom === '' || $periodTo === '') {
            return $this->json(['error' => 'periodFrom and periodTo are required'], 400);
        }

        try {
            return $this->buildResponse($companyId, $marketplace, $periodFrom, $periodTo);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
            ], 500);
        }
    }

    private function buildResponse(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): JsonResponse {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ];

        // 1. summary — общие цифры
        $totals = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)             AS total_records,
                SUM(s.total_revenue) AS total_revenue
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            SQL,
            $params,
        );

        $uniqueOrders = $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(DISTINCT s.external_order_id)
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            SQL,
            $params,
        );

        $totalRecords = (int) ($totals['total_records'] ?? 0);
        $summary = [
            'total_records'        => $totalRecords,
            'total_revenue'        => $totals['total_revenue'] ?? '0',
            'unique_external_orders' => (int) $uniqueOrders,
            'duplicates_count'     => $totalRecords - (int) $uniqueOrders,
        ];

        // 2. duplicates — записи с одинаковым external_order_id
        $duplicates = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.external_order_id,
                COUNT(*)                           AS count,
                SUM(s.total_revenue)               AS total_revenue,
                array_agg(s.id)                    AS ids,
                array_agg(s.sale_date::text)       AS dates,
                array_agg(s.total_revenue::text)   AS amounts
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            GROUP BY s.external_order_id
            HAVING COUNT(*) > 1
            ORDER BY SUM(s.total_revenue) DESC
            LIMIT 50
            SQL,
            $params,
        );

        // 3. top_revenue — 20 записей с самой большой total_revenue
        $topRevenue = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.id,
                s.external_order_id,
                s.sale_date::text AS sale_date,
                s.total_revenue,
                s.quantity,
                s.price_per_unit,
                l.name  AS listing_title,
                l.marketplace_sku AS listing_sku
            FROM marketplace_sales s
            JOIN marketplace_listings l ON l.id = s.listing_id
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            ORDER BY s.total_revenue DESC
            LIMIT 20
            SQL,
            $params,
        );

        // 4. revenue_by_external_order — сумма при группировке по external_order_id
        $revenueByOrder = $this->connection->fetchOne(
            <<<'SQL'
            SELECT SUM(grouped_revenue) AS total_by_unique_orders
            FROM (
                SELECT external_order_id, SUM(total_revenue) AS grouped_revenue
                FROM marketplace_sales
                WHERE company_id  = :companyId
                  AND marketplace = :marketplace
                  AND sale_date  >= :periodFrom
                  AND sale_date  <= :periodTo
                GROUP BY external_order_id
            ) sub
            SQL,
            $params,
        );

        return $this->json([
            'summary'                  => $summary,
            'duplicates'               => $duplicates,
            'top_revenue'              => $topRevenue,
            'revenue_by_external_order' => $revenueByOrder,
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }
}
