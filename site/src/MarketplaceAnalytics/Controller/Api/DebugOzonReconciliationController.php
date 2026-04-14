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
 * Отладочный эндпоинт для сверки выручки Ozon.
 *
 * Сравнивает SUM(marketplace_sales.total_revenue) с наличием raw-документов за период.
 * Помогает диагностировать расхождение между данными в ЛК Ozon и нашей БД.
 *
 * Использование:
 *   GET /api/marketplace-analytics/debug/ozon-reconciliation
 *       ?periodFrom=2026-04-01&periodTo=2026-04-13
 */
#[Route(
    path: '/api/marketplace-analytics/debug/ozon-reconciliation',
    name: 'api_marketplace_analytics_debug_ozon_reconciliation',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugOzonReconciliationController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $periodFromStr = (string) $request->query->get('periodFrom', '');
        $periodToStr   = (string) $request->query->get('periodTo', '');

        if ($periodFromStr === '' || $periodToStr === '') {
            return $this->json(['error' => 'periodFrom and periodTo are required (Y-m-d)'], 422);
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

        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $salesByDate  = $this->fetchSalesByDate($companyId, $periodFrom, $periodTo);
        $rawDocuments = $this->fetchRawDocuments($companyId, $periodFrom, $periodTo);
        $stornoSales  = $this->fetchStornoSales($companyId, $periodFrom, $periodTo);
        $orphanCount  = $this->fetchSalesWithoutRawDocument($companyId, $periodFrom, $periodTo);
        $gaps         = $this->buildGaps($salesByDate, $rawDocuments, $orphanCount);

        $totalCount   = 0;
        $totalRevenue = 0.0;
        foreach ($salesByDate as $row) {
            $totalCount   += $row['count'];
            $totalRevenue += $row['revenue'];
        }

        $stornoRevenue = 0.0;
        foreach ($stornoSales as $s) {
            $stornoRevenue += $s['totalRevenue'];
        }

        return new JsonResponse([
            'period' => [
                'from' => $periodFrom->format('Y-m-d'),
                'to'   => $periodTo->format('Y-m-d'),
            ],
            'sales' => [
                'count'        => $totalCount,
                'totalRevenue' => round($totalRevenue, 2),
                'byDate'       => $salesByDate,
            ],
            'rawDocuments' => [
                'count'     => count($rawDocuments),
                'documents' => $rawDocuments,
            ],
            'stornoSales' => [
                'count'        => count($stornoSales),
                'totalRevenue' => round($stornoRevenue, 2),
                'items'        => $stornoSales,
            ],
            'gaps' => $gaps,
        ]);
    }

    /**
     * @return array<string, array{count: int, revenue: float}>
     */
    private function fetchSalesByDate(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.sale_date::text                 AS dt,
                COUNT(*)                          AS cnt,
                COALESCE(SUM(s.total_revenue), 0) AS revenue
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

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['dt']] = [
                'count'   => (int) $row['cnt'],
                'revenue' => round((float) $row['revenue'], 2),
            ];
        }

        return $result;
    }

    /**
     * Фильтр по перекрытию периодов: документ покрывает хотя бы часть запрошенного диапазона.
     * Используется period_from/period_to документа, а не synced_at.
     *
     * @return list<array{id: string, status: string|null, syncedAt: string, documentType: string, operationsCount: int, periodFrom: string, periodTo: string}>
     */
    private function fetchRawDocuments(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                mrd.id,
                mrd.processing_status,
                mrd.synced_at::text   AS synced_at,
                mrd.document_type,
                mrd.records_count,
                mrd.period_from::text AS period_from,
                mrd.period_to::text   AS period_to
            FROM marketplace_raw_documents mrd
            WHERE mrd.company_id  = :companyId
              AND mrd.marketplace = 'ozon'
              AND mrd.period_from <= :periodTo
              AND mrd.period_to   >= :periodFrom
            ORDER BY mrd.period_from, mrd.synced_at
            SQL,
            [
                'companyId'  => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo'   => $to->format('Y-m-d'),
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'              => (string) $row['id'],
                'status'          => $row['processing_status'] !== null ? (string) $row['processing_status'] : null,
                'syncedAt'        => (string) $row['synced_at'],
                'documentType'    => (string) $row['document_type'],
                'operationsCount' => (int) $row['records_count'],
                'periodFrom'      => (string) $row['period_from'],
                'periodTo'        => (string) $row['period_to'],
            ];
        }

        return $result;
    }

    /**
     * Сторно-продажи: external_order_id с суффиксом _storno
     * (OzonSalesRawProcessor добавляет суффикс при accruals_for_sale < 0).
     *
     * @return list<array{externalOrderId: string, totalRevenue: float, saleDate: string}>
     */
    private function fetchStornoSales(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                s.external_order_id,
                s.total_revenue,
                s.sale_date::text AS sale_date
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = 'ozon'
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
              AND s.external_order_id LIKE '%_storno'
            ORDER BY s.sale_date, s.external_order_id
            SQL,
            [
                'companyId'  => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo'   => $to->format('Y-m-d'),
            ],
        );

        return array_map(
            static fn (array $r): array => [
                'externalOrderId' => (string) $r['external_order_id'],
                'totalRevenue'    => round((float) $r['total_revenue'], 2),
                'saleDate'        => (string) $r['sale_date'],
            ],
            $rows,
        );
    }

    /**
     * Продажи без ссылки на raw-документ (raw_document_id IS NULL).
     * Указывает на продажи, созданные вне daily pipeline (вручную или legacy-способом).
     */
    private function fetchSalesWithoutRawDocument(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): int {
        $count = $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
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

        return (int) $count;
    }

    /**
     * Пробелы: даты с продажами, для которых нет покрывающего raw-документа.
     *
     * @param array<string, array{count: int, revenue: float}>              $salesByDate
     * @param list<array{periodFrom: string, periodTo: string, ...}>        $rawDocuments
     *
     * @return array{datesWithoutRawDocuments: list<string>, salesWithoutRawDocument: int}
     */
    private function buildGaps(array $salesByDate, array $rawDocuments, int $salesWithoutRawDocument): array
    {
        // Собираем все даты, покрытые raw-документами
        $coveredDates = [];
        foreach ($rawDocuments as $doc) {
            $cursor = new \DateTimeImmutable($doc['periodFrom']);
            $end    = new \DateTimeImmutable($doc['periodTo']);
            while ($cursor <= $end) {
                $coveredDates[$cursor->format('Y-m-d')] = true;
                $cursor = $cursor->modify('+1 day');
            }
        }

        // Даты с продажами, у которых нет raw-документа
        $datesWithoutRawDocuments = [];
        foreach (array_keys($salesByDate) as $date) {
            if (!isset($coveredDates[$date])) {
                $datesWithoutRawDocuments[] = $date;
            }
        }
        sort($datesWithoutRawDocuments);

        return [
            'datesWithoutRawDocuments' => $datesWithoutRawDocuments,
            'salesWithoutRawDocument'  => $salesWithoutRawDocument,
        ];
    }
}
