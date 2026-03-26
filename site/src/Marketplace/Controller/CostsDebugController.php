<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use App\Marketplace\Application\Reconciliation\OzonReportParserFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\CostReconciliationQuery;
use App\Marketplace\Infrastructure\Query\CostsVerifyQuery;
use App\Marketplace\Infrastructure\Query\RawOperationsAnalysisQuery;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт сверки затрат Ozon.
 *
 * Использование:
 *   GET /marketplace/costs/debug/verify?marketplace=ozon&year=2026&month=3
 *
 * Как сверять:
 *   1. Открыть Ozon Seller → Финансы → Детализация начислений
 *   2. Скачать .xlsx за тот же период
 *   3. Сравнить итоги по каждой категории с полем totals_by_category
 *   4. Сравнить grand_total с итоговой суммой в xlsx
 */
#[Route('/marketplace/costs/debug')]
#[IsGranted('ROLE_USER')]
final class CostsDebugController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService        $companyService,
        private readonly CostsVerifyQuery            $verifyQuery,
        private readonly RawOperationsAnalysisQuery  $rawOperationsQuery,
        private readonly OzonReportParserFacade      $parserFacade,
        private readonly CostReconciliationQuery     $reconciliationQuery,
        private readonly Connection                  $connection,
    ) {
    }

    /**
     * Компактный JSON для ручной сверки с «Детализацией начислений» Ozon.
     *
     * Скопируй результат и сравни с xlsx-отчётом из ЛК Ozon.
     *
     * Параметры:
     *   ?xlsx_total=3761721.62 — итог колонки «Сумма» из xlsx (только расходные группы).
     *                            Если передан — period_health.reconciliation покажет OK или MISMATCH.
     */
    #[Route('/verify', name: 'marketplace_costs_debug_verify', methods: ['GET'])]
    public function verify(Request $request): JsonResponse
    {
        [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo] = $this->resolveParams($request);

        $xlsxTotal = $request->query->get('xlsx_total') !== null
            ? (float) $request->query->get('xlsx_total')
            : null;

        $data = $this->verifyQuery->run($companyId, $marketplace, $periodFrom, $periodTo, $xlsxTotal);

        return $this->json([
            'meta' => [
                'marketplace'  => $marketplace,
                'period'       => "{$periodFrom} – {$periodTo}",
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            'checks' => $data,
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * Анализ operation_type и service_name из raw-документов за период.
     *
     * Используй для диагностики расхождений между нашим grand_total и xlsx.
     * Показывает реальное распределение operation_type / service_name → category_code.
     *
     * GET /marketplace/costs/debug/raw-operations?marketplace=ozon&year=2026&month=2
     * GET /marketplace/costs/debug/raw-operations?marketplace=ozon&year=2026&month=2&category=ozon_logistic_direct
     */
    #[Route('/raw-operations', name: 'marketplace_costs_debug_raw_operations', methods: ['GET'])]
    public function rawOperations(Request $request): JsonResponse
    {
        [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo] = $this->resolveParams($request);

        $filterCategory = $request->query->get('category') ?: null;

        $data = $this->rawOperationsQuery->run(
            $companyId,
            $marketplace,
            $periodFrom,
            $periodTo,
            $filterCategory,
        );

        return $this->json([
            'meta' => [
                'marketplace'     => $marketplace,
                'period'          => "{$periodFrom} – {$periodTo}",
                'filter_category' => $filterCategory,
                'generated_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            'data' => $data,
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * Поиск операций в raw JSON документах по ключевому слову.
     *
     * Используй для поиска неизвестных operation_type / service_name
     * которые не попали в marketplace_costs.
     *
     * GET /marketplace/costs/debug/raw-search?marketplace=ozon&year=2026&month=2&q=отмен
     * GET /marketplace/costs/debug/raw-search?marketplace=ozon&year=2026&month=2&q=Decompensation
     *
     * Параметры:
     *   ?q=keyword — ключевое слово для поиска в operation_type и operation_type_name
     *   ?limit=20  — максимум результатов (default: 20)
     */
    #[Route('/raw-search', name: 'marketplace_costs_debug_raw_search', methods: ['GET'])]
    public function rawSearch(Request $request): JsonResponse
    {
        [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo] = $this->resolveParams($request);

        $keyword = trim((string) $request->query->get('q', ''));
        $limit   = max(1, min(100, (int) $request->query->get('limit', 20)));

        if ($keyword === '') {
            return $this->json(['error' => 'Параметр ?q= обязателен'], 400);
        }

        // Ищем в raw JSON через PostgreSQL JSONB
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                d.id                                    AS doc_id,
                d.period_from::text                     AS period_from,
                d.period_to::text                       AS period_to,
                op->>'operation_id'                     AS operation_id,
                op->>'operation_type'                   AS operation_type,
                op->>'operation_type_name'              AS operation_type_name,
                op->>'type'                             AS type,
                op->>'amount'                           AS amount,
                op->>'operation_date'                   AS operation_date,
                op->>'services'                         AS services
            FROM marketplace_raw_documents d,
                 jsonb_array_elements(
                     CASE
                         WHEN jsonb_typeof(d.raw_data::jsonb->'result'->'operations') = 'array'
                         THEN d.raw_data::jsonb->'result'->'operations'
                         WHEN jsonb_typeof(d.raw_data::jsonb) = 'array'
                         THEN d.raw_data::jsonb
                         ELSE '[]'::jsonb
                     END
                 ) AS op
            WHERE d.company_id  = :companyId
              AND d.marketplace = :marketplace
              AND d.period_from >= :periodFrom
              AND d.period_to  <= :periodTo
              AND (
                  op->>'operation_type'         ILIKE :keyword
                  OR op->>'operation_type_name' ILIKE :keyword
                  OR op->>'services'            ILIKE :keyword
              )
            ORDER BY op->>'operation_date'
            LIMIT {$limit}
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
                'keyword'     => '%' . $keyword . '%',
            ],
        );

        return $this->json([
            'meta' => [
                'marketplace'  => $marketplace,
                'period'       => "{$periodFrom} – {$periodTo}",
                'keyword'      => $keyword,
                'found'        => count($rows),
                'limit'        => $limit,
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            'results' => array_map(static fn (array $r) => [
                'doc_id'             => $r['doc_id'],
                'period'             => $r['period_from'] . ' – ' . $r['period_to'],
                'operation_id'       => $r['operation_id'],
                'operation_type'     => $r['operation_type'],
                'operation_type_name'=> $r['operation_type_name'],
                'type'               => $r['type'],
                'amount'             => $r['amount'],
                'operation_date'     => $r['operation_date'],
                'services'           => json_decode($r['services'] ?? '[]', true),
            ], $rows),
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * Парсит загруженный xlsx и возвращает ReportResult без записи в БД.
     * Используй для диагностики расхождений в сверке.
     *
     * POST /marketplace/costs/debug/xlsx-parse
     * Form-data: xlsx_file=@file.xlsx
     */
    #[Route('/xlsx-parse', name: 'marketplace_costs_debug_xlsx_parse', methods: ['POST'])]
    public function xlsxParse(Request $request): JsonResponse
    {
        $file = $request->files->get('xlsx_file');
        if ($file === null) {
            return $this->json(['error' => 'Файл не загружен. Передай xlsx_file в form-data.'], 400);
        }

        try {
            $result = $this->parserFacade->parseFromPath($file->getPathname());
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Ошибка парсинга: ' . $e->getMessage()], 500);
        }

        return $this->json([
            'meta' => [
                'generated_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'hint'           => 'Это ReportResult из xlsx без записи в БД. Сравни totalNet с нашим xlsx_comparable из verify.',
            ],
            'report' => [
                'period'         => $result['period'],
                'total_accruals' => $result['totalAccruals'],
                'total_expenses' => $result['totalExpenses'],
                'total_storno'   => $result['totalStorno'],
                'total_net'      => $result['totalNet'],
                'lines_count'    => count($result['lines']),
                'lines'          => array_map(static fn(array $l) => [
                    'typeName'     => $l['typeName'],
                    'serviceGroup' => $l['serviceGroup'],
                    'baseSign'     => $l['baseSign'],
                    'accruals'     => $l['accruals'],
                    'expenses'     => $l['expenses'],
                    'storno'       => $l['storno'],
                    'zero'         => $l['zero'],
                    'line_net'     => round(
                        $l['accruals']['total'] + $l['expenses']['total'] + $l['storno']['total'],
                        2
                    ),
                ], $result['lines']),
            ],
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * Детальное сравнение xlsx с API данными — показывает расхождение по каждой группе.
     *
     * POST /marketplace/costs/debug/reconcile-debug?marketplace=ozon&year=2026&month=2
     * Form-data: xlsx_file=@file.xlsx
     */
    #[Route('/reconcile-debug', name: 'marketplace_costs_debug_reconcile_debug', methods: ['POST'])]
    public function reconcileDebug(Request $request): JsonResponse
    {
        [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo] = $this->resolveParams($request);

        $file = $request->files->get('xlsx_file');
        if ($file === null) {
            return $this->json(['error' => 'Файл не загружен. Передай xlsx_file в form-data.'], 400);
        }

        try {
            $reportResult = $this->parserFacade->parseFromPath($file->getPathname());
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Ошибка парсинга xlsx: ' . $e->getMessage()], 500);
        }

        $reconciliation = $this->reconciliationQuery->reconcile(
            $companyId, $marketplace, $periodFrom, $periodTo, $reportResult,
        );

        // Детализация по группам xlsx vs наш verify
        $verifyData = $this->verifyQuery->run($companyId, $marketplace, $periodFrom, $periodTo, null);

        return $this->json([
            'meta' => [
                'marketplace'  => $marketplace,
                'period'       => "{$periodFrom} – {$periodTo}",
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            'reconciliation' => $reconciliation,
            'xlsx_report' => [
                'period'         => $reportResult['period'],
                'total_accruals' => $reportResult['totalAccruals'],
                'total_expenses' => $reportResult['totalExpenses'],
                'total_storno'   => $reportResult['totalStorno'],
                'total_net'      => $reportResult['totalNet'],
                'xlsx_total_abs' => abs($reportResult['totalNet']),
                'lines_count'    => count($reportResult['lines']),
                'by_service_group' => $this->aggregateByServiceGroup($reportResult['lines']),
            ],
            'api_data' => [
                'net_amount'         => $verifyData['grand_total']['net_amount'] ?? null,
                'costs_amount'       => $verifyData['grand_total']['costs_amount'] ?? null,
                'storno_amount'      => $verifyData['grand_total']['storno_amount'] ?? null,
                'return_revenue'     => $verifyData['returns_reconciliation']['total_refund_amount'] ?? null,
                'xlsx_comparable'    => $reconciliation['xlsx_comparable'],
            ],
            'hint' => [
                'formula'        => 'xlsx_comparable = api_net_amount + return_revenue_amount',
                'delta_meaning'  => 'delta > 0: xlsx больше нашего comparable. delta < 0: xlsx меньше.',
                'check_groups'   => 'Сравни by_service_group из xlsx с totals_by_category из verify.',
            ],
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * Страница отладки сверки xlsx с API данными.
     *
     * GET  /marketplace/costs/debug/reconcile  — форма загрузки
     * POST /marketplace/costs/debug/reconcile  — результат сверки на той же странице
     */
    #[Route('/reconcile', name: 'marketplace_costs_debug_reconcile_page', methods: ['GET', 'POST'])]
    public function reconcilePage(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo] = $this->resolveParams($request);

        $templateVars = [
            'active_tab'             => 'costs_debug',
            'marketplace'            => $marketplace,
            'available_marketplaces' => MarketplaceType::cases(),
            'year'                   => $year,
            'month'                  => $month,
        ];

        if ($request->isMethod('POST')) {
            $file = $request->files->get('xlsx_file');

            if ($file === null) {
                $this->addFlash('error', 'Файл не загружен.');
                return $this->render('@Marketplace/costs/reconciliation_debug.html.twig', $templateVars);
            }

            try {
                $reportResult = $this->parserFacade->parseFromPath($file->getPathname());
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Ошибка парсинга xlsx: ' . $e->getMessage());
                return $this->render('@Marketplace/costs/reconciliation_debug.html.twig', $templateVars);
            }

            $reconciliation = $this->reconciliationQuery->reconcile(
                $companyId, $marketplace, $periodFrom, $periodTo, $reportResult,
            );

            $verifyData = $this->verifyQuery->run($companyId, $marketplace, $periodFrom, $periodTo, null);

            $templateVars['result'] = [
                'reconciliation' => $reconciliation,
                'xlsx_report'    => [
                    'period'           => $reportResult['period'],
                    'total_accruals'   => $reportResult['totalAccruals'],
                    'total_expenses'   => $reportResult['totalExpenses'],
                    'total_storno'     => $reportResult['totalStorno'],
                    'total_net'        => $reportResult['totalNet'],
                    'lines_count'      => count($reportResult['lines']),
                    'by_service_group' => $this->aggregateByServiceGroup($reportResult['lines']),
                ],
                'api_data'       => [
                    'net_amount'      => $verifyData['grand_total']['net_amount'] ?? null,
                    'costs_amount'    => $verifyData['grand_total']['costs_amount'] ?? null,
                    'storno_amount'   => $verifyData['grand_total']['storno_amount'] ?? null,
                    'return_revenue'  => $verifyData['returns_reconciliation']['total_refund_amount'] ?? null,
                    'xlsx_comparable' => $reconciliation['xlsx_comparable'],
                ],
                'api_categories' => $verifyData['totals_by_category'] ?? [],
            ];
        }

        return $this->render('@Marketplace/costs/reconciliation_debug.html.twig', $templateVars);
    }

    private function aggregateByServiceGroup(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $group = $line['serviceGroup'] ?: 'Без группы';
            if (!isset($groups[$group])) {
                $groups[$group] = ['serviceGroup' => $group, 'total' => 0.0, 'types' => []];
            }
            $lineNet = $line['accruals']['total'] + $line['expenses']['total'] + $line['storno']['total'];
            $groups[$group]['total'] = round($groups[$group]['total'] + $lineNet, 2);
            $groups[$group]['types'][] = ['typeName' => $line['typeName'], 'net' => round($lineNet, 2)];
        }
        usort($groups, fn($a, $b) => $a['total'] <=> $b['total']);
        return array_values($groups);
    }

    // -------------------------------------------------------------------------

    /**
     * Версия задеплоенного OzonServiceCategoryMap.
     * Используй для проверки что нужный маппинг попал на прод.
     *
     * GET /marketplace/costs/debug/map-version
     */
    #[Route('/map-version', name: 'marketplace_costs_debug_map_version', methods: ['GET'])]
    public function mapVersion(): JsonResponse
    {
        return $this->json([
            'map' => OzonServiceCategoryMap::getMapStats(),
            'hint' => 'Сравни version с последним коммитом в OzonServiceCategoryMap',
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{string, string, int, int, string, string}
     */
    private function resolveParams(Request $request): array
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace = $request->query->get('marketplace') ?: MarketplaceType::OZON->value;
        $year        = (int) $request->query->get('year', date('Y'));
        $month       = (int) $request->query->get('month', date('n'));

        if (MarketplaceType::tryFrom($marketplace) === null) {
            $marketplace = MarketplaceType::OZON->value;
        }

        $periodFrom = sprintf('%d-%02d-01', $year, $month);
        $periodTo   = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        return [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo];
    }
}
