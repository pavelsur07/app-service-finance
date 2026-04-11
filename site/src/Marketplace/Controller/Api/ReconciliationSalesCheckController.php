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
 * TEMPORARY — удалить после диагностики расхождения в продажах.
 *
 * Диагностика: итоги продаж из marketplace_sales за период,
 * разбивка по дням, граничные дни.
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationSalesCheckController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        '/api/marketplace/reconciliation/debug/sales-check',
        name: 'api_marketplace_reconciliation_debug_sales_check',
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

        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ];

        // 1. sales_total
        $salesTotal = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)              AS count,
                SUM(s.total_revenue)  AS total_revenue,
                SUM(s.quantity)       AS total_quantity,
                MIN(s.sale_date)::text AS min_date,
                MAX(s.sale_date)::text AS max_date
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            SQL,
            $params,
        );

        // 2. sales_by_date
        $salesByDate = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.sale_date::text AS sale_date,
                COUNT(*)              AS count,
                SUM(s.total_revenue)  AS total_revenue
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            GROUP BY s.sale_date
            ORDER BY s.sale_date
            SQL,
            $params,
        );

        // 3. xlsx_sales_total — константа для удобства сравнения
        $xlsxSalesTotal = 5794309.01;

        // 4. boundary_sales — продажи на границах периода
        $boundaryParams = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
        ];

        $prevDay = (new \DateTimeImmutable($periodFrom))
            ->modify('-1 day')
            ->format('Y-m-d');

        $nextDay = (new \DateTimeImmutable($periodTo))
            ->modify('+1 day')
            ->format('Y-m-d');

        $prevDaySales = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS count, COALESCE(SUM(s.total_revenue), 0) AS total_revenue
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date   = :saleDate
            SQL,
            array_merge($boundaryParams, ['saleDate' => $prevDay]),
        );

        $nextDaySales = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS count, COALESCE(SUM(s.total_revenue), 0) AS total_revenue
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date   = :saleDate
            SQL,
            array_merge($boundaryParams, ['saleDate' => $nextDay]),
        );

        // delta
        $apiTotal = (float) ($salesTotal['total_revenue'] ?? 0);
        $delta    = round($apiTotal - $xlsxSalesTotal, 2);

        return $this->json([
            'sales_total'      => $salesTotal,
            'sales_by_date'    => $salesByDate,
            'xlsx_sales_total' => $xlsxSalesTotal,
            'delta'            => $delta,
            'boundary_sales'   => [
                $prevDay => $prevDaySales,
                $nextDay => $nextDaySales,
            ],
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }
}
