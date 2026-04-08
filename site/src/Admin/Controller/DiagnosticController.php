<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/diagnostic', name: 'admin_diagnostic_')]
#[IsGranted('ROLE_ADMIN')]
final class DiagnosticController extends AbstractController
{
    #[Route('/cost-categories', name: 'cost_categories', methods: ['GET'])]
    public function costCategories(Connection $connection): JsonResponse
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT id, name, code, is_system, is_active, deleted_at, company_id, marketplace
             FROM marketplace_cost_categories
             ORDER BY company_id, marketplace, name
             LIMIT 100'
        );

        return new JsonResponse($rows);
    }

    #[Route('/costs-without-category', name: 'costs_without_category', methods: ['GET'])]
    public function costsWithoutCategory(Connection $connection): JsonResponse
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT
                id,
                company_id,
                cost_date,
                description,
                amount,
                raw_document_id,
                marketplace
             FROM marketplace_costs
             WHERE category_id IS NULL
             ORDER BY cost_date DESC
             LIMIT 50'
        );

        return new JsonResponse($rows);
    }

    #[Route('/fix-wb-costs-categories/{companyId}', name: 'fix_wb_costs', methods: ['GET'], requirements: ['companyId' => '[0-9a-f-]{36}'])]
    public function fixWbCostsCategories(string $companyId, Connection $connection): JsonResponse
    {

        $categories = $connection->fetchAllAssociative(
            'SELECT id, code
             FROM marketplace_cost_categories
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND is_active = true
               AND deleted_at IS NULL',
            ['companyId' => $companyId, 'marketplace' => 'wildberries'],
        );

        $codeToId = [];
        foreach ($categories as $cat) {
            $codeToId[$cat['code']] = $cat['id'];
        }

        $descriptionToCode = [
            'Логистика до покупателя' => 'logistics_delivery',
            'Логистика возврат' => 'logistics_return',
            'Логистика складские операции' => 'warehouse_logistics',
            'Хранение WB' => 'storage',
            'Штраф WB' => 'penalty',
            'Эквайринг' => 'acquiring',
            'Комиссия маркетплейса' => 'commission',
            'Логистика обработка на ПВЗ' => 'pvz_processing',
            'Обработка товара WB' => 'product_processing',
            'Компенсация скидки по программе лояльности WB' => 'wb_loyalty_discount_compensation',
            'Оказание услуг «WB Продвижение»' => 'wb_okazanie_uslug_wb_prodvizhenie',
            'Списание за отзыв' => 'wb_spisanie_za_otzyv',
        ];

        $results = [];
        foreach ($descriptionToCode as $description => $code) {
            $categoryId = $codeToId[$code] ?? null;
            if ($categoryId === null) {
                $results[$description] = ['error' => "Category with code '{$code}' not found"];
                continue;
            }

            $updated = $connection->executeStatement(
                'UPDATE marketplace_costs
                 SET category_id = :categoryId
                 WHERE company_id = :companyId
                   AND marketplace = :marketplace
                   AND category_id IS NULL
                   AND description = :description',
                [
                    'categoryId' => $categoryId,
                    'companyId' => $companyId,
                    'marketplace' => 'wildberries',
                    'description' => $description,
                ],
            );

            $results[$description] = ['updated' => $updated, 'category_code' => $code];
        }

        return new JsonResponse($results);
    }

    #[Route('/wb-costs-check/{companyId}', name: 'wb_costs_check', methods: ['GET'], requirements: ['companyId' => '[0-9a-f-]{36}'])]
    public function wbCostsCheck(string $companyId, Connection $connection): JsonResponse
    {
        $byMarketplace = $connection->fetchAllAssociative(
            'SELECT marketplace, COUNT(*) as cnt
             FROM marketplace_costs
             WHERE company_id = :companyId AND category_id IS NULL
             GROUP BY marketplace',
            ['companyId' => $companyId],
        );

        $counts = array_column($byMarketplace, 'cnt', 'marketplace');

        return new JsonResponse([
            'costs_without_category_by_marketplace' => $byMarketplace,
            'wb_costs_without_category_total' => (int) ($counts['wildberries'] ?? 0),
        ]);
    }

    #[Route('/wb-costs-sample/{companyId}', name: 'wb_costs_sample', methods: ['GET'], requirements: ['companyId' => '[0-9a-f-]{36}'])]
    public function wbCostsSample(string $companyId, Connection $connection): JsonResponse
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT id, marketplace, category_id, description, company_id
             FROM marketplace_costs
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND category_id IS NULL
             LIMIT 5',
            ['companyId' => $companyId, 'marketplace' => 'wildberries'],
        );

        return new JsonResponse($rows);
    }

    #[Route('/wb-barcodes/{companyId}', name: 'wb_barcodes', methods: ['GET'], requirements: ['companyId' => '[0-9a-f-]{36}'])]
    public function wbBarcodes(string $companyId, Connection $connection): JsonResponse
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT barcode, listing_id
             FROM marketplace_listing_barcodes
             WHERE company_id = :companyId
               AND marketplace = :marketplace
             ORDER BY barcode
             LIMIT 100',
            ['companyId' => $companyId, 'marketplace' => 'wildberries'],
        );

        return new JsonResponse($rows);
    }

    #[Route('/mapping-errors', name: 'mapping_errors', methods: ['GET'])]
    public function mappingErrors(Connection $connection): JsonResponse
    {
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                company_id,
                marketplace,
                year,
                month,
                service_name,
                operation_type,
                rows_count,
                total_amount,
                sample_raw_json,
                detected_at,
                resolved_at
            FROM marketplace_mapping_errors
            WHERE resolved_at IS NULL
            ORDER BY detected_at DESC
            LIMIT 50
            SQL,
        );

        foreach ($rows as &$row) {
            if (isset($row['sample_raw_json'])) {
                $row['sample_raw_json'] = json_decode($row['sample_raw_json'], true);
            }
        }
        unset($row);

        return new JsonResponse($rows);
    }

    #[Route('/ozon-unknown-services', name: 'ozon_unknown_services', methods: ['GET'])]
    public function ozonUnknownServices(Connection $connection): JsonResponse
    {
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                mc.description,
                COUNT(*)        AS cnt,
                SUM(mc.amount)  AS total_amount,
                MIN(mc.cost_date)::text AS first_seen,
                MAX(mc.cost_date)::text AS last_seen
            FROM marketplace_costs mc
            JOIN marketplace_cost_categories mcc ON mcc.id = mc.category_id
            WHERE mcc.code      = 'ozon_other_service'
              AND mc.marketplace = 'ozon'
            GROUP BY mc.description
            ORDER BY cnt DESC
            LIMIT 50
            SQL,
        );

        return new JsonResponse($rows);
    }

    #[Route('/delete-wb-costs-no-listing/{companyId}', name: 'delete_wb_costs_no_listing', methods: ['GET'], requirements: ['companyId' => '[0-9a-f-]{36}'])]
    public function deleteWbCostsNoListing(string $companyId, Request $request, Connection $connection): JsonResponse
    {
        $confirm = $request->query->get('confirm') === '1';
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        if ($dateFrom === null || $dateTo === null) {
            return new JsonResponse(['error' => 'date_from and date_to query parameters are required'], 400);
        }

        $params = [
            'companyId' => $companyId,
            'marketplace' => 'wildberries',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];

        $where = 'company_id = :companyId
              AND marketplace = :marketplace
              AND listing_id IS NULL
              AND document_id IS NULL
              AND cost_date BETWEEN :dateFrom AND :dateTo';

        if (!$confirm) {
            $count = (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM marketplace_costs WHERE {$where}",
                $params,
            );

            return new JsonResponse(['action' => 'preview', 'count' => $count]);
        }

        $deleted = $connection->executeStatement(
            "DELETE FROM marketplace_costs WHERE {$where}",
            $params,
        );

        return new JsonResponse(['action' => 'deleted', 'count' => $deleted]);
    }

    #[Route('/ozon-other-service-with-doc/{companyId}', name: 'ozon_other_with_doc', methods: ['GET'], requirements: ['companyId' => '[0-9a-f-]{36}'])]
    public function ozonOtherServiceWithDoc(string $companyId, Connection $connection): JsonResponse
    {
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                mc.id,
                mc.raw_document_id,
                mc.document_id,
                mc.cost_date,
                mc.description,
                mcc.code as category_code
            FROM marketplace_costs mc
            JOIN marketplace_cost_categories mcc ON mc.category_id = mcc.id
            WHERE mc.company_id = :companyId
              AND mc.marketplace = 'ozon'
              AND mcc.code = 'ozon_other_service'
              AND mc.cost_date BETWEEN '2026-03-01' AND '2026-03-31'
            LIMIT 20
            SQL,
            ['companyId' => $companyId],
        );

        return new JsonResponse($rows);
    }

    #[Route('/delete-costs-for-reprocess', name: 'delete_costs_for_reprocess', methods: ['GET'])]
    public function deleteCostsForReprocess(Request $request, Connection $connection): JsonResponse
    {
        $confirm = $request->query->get('confirm') === '1';
        $marketplace = $request->query->get('marketplace');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        if ($marketplace === null || $dateFrom === null || $dateTo === null) {
            return new JsonResponse(['error' => 'marketplace, date_from and date_to query parameters are required'], 400);
        }

        $params = [
            'marketplace' => $marketplace,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];

        $breakdown = $connection->fetchAllAssociative(
            'SELECT company_id, COUNT(*) as cnt
             FROM marketplace_costs
             WHERE marketplace = :marketplace
               AND cost_date BETWEEN :dateFrom AND :dateTo
               AND document_id IS NULL
             GROUP BY company_id',
            $params,
        );

        $total = array_sum(array_column($breakdown, 'cnt'));

        if (!$confirm) {
            return new JsonResponse([
                'action' => 'preview',
                'total' => $total,
                'breakdown' => $breakdown,
            ]);
        }

        $deleted = $connection->executeStatement(
            'DELETE FROM marketplace_costs
             WHERE marketplace = :marketplace
               AND cost_date BETWEEN :dateFrom AND :dateTo
               AND document_id IS NULL',
            $params,
        );

        return new JsonResponse([
            'action' => 'deleted',
            'total' => $deleted,
            'breakdown' => $breakdown,
        ]);
    }

    #[Route('/tmp-ozon-costs-sample', name: 'tmp_ozon_costs_sample', methods: ['GET'])]
    public function tmpOzonCostsSample(Connection $connection): JsonResponse
    {
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT external_id, cost_date, description, raw_document_id, document_id
            FROM marketplace_costs
            WHERE company_id = 'b57d7682-505f-4b74-86f8-953d32d59874'
              AND marketplace = 'ozon'
              AND cost_date BETWEEN '2026-03-01' AND '2026-03-31'
            LIMIT 10
            SQL,
        );

        return new JsonResponse($rows);
    }

    #[Route('/fix-ozon-other-service/{companyId}', name: 'fix_ozon_other_service', methods: ['GET'], requirements: ['companyId' => '[0-9a-f-]{36}'])]
    public function fixOzonOtherService(string $companyId, Request $request, Connection $connection): JsonResponse
    {
        $confirm = $request->query->get('confirm') === '1';

        $categories = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT id, code
            FROM marketplace_cost_categories
            WHERE company_id = :companyId
              AND marketplace = 'ozon'
              AND is_active = true
              AND deleted_at IS NULL
              AND code IN ('ozon_brand_commission', 'ozon_premium_correction', 'ozon_service_correction')
            SQL,
            ['companyId' => $companyId],
        );

        $codeToId = [];
        foreach ($categories as $cat) {
            $codeToId[$cat['code']] = $cat['id'];
        }

        $descriptionToCode = [
            'MarketplaceServiceBrandCommission' => 'ozon_brand_commission',
            'Корректировка суммы акта о премии' => 'ozon_premium_correction',
            'Корректировки стоимости услуг'     => 'ozon_service_correction',
        ];

        $where = <<<'SQL'
            company_id = :companyId
              AND marketplace = 'ozon'
              AND category_id = (
                  SELECT id FROM marketplace_cost_categories
                  WHERE company_id = :companyId
                    AND marketplace = 'ozon'
                    AND code = 'ozon_other_service'
              )
              AND description = :description
              AND document_id IS NULL
            SQL;

        $results = [];
        foreach ($descriptionToCode as $description => $code) {
            $categoryId = $codeToId[$code] ?? null;
            if ($categoryId === null) {
                $results[$description] = ['error' => "Category with code '{$code}' not found"];
                continue;
            }

            $params = ['companyId' => $companyId, 'description' => $description];

            if (!$confirm) {
                $count = (int) $connection->fetchOne(
                    "SELECT COUNT(*) FROM marketplace_costs WHERE {$where}",
                    $params,
                );
                $results[$description] = ['action' => 'preview', 'count' => $count, 'target_code' => $code];
            } else {
                $updated = $connection->executeStatement(
                    "UPDATE marketplace_costs SET category_id = :categoryId WHERE {$where}",
                    array_merge($params, ['categoryId' => $categoryId]),
                );
                $results[$description] = ['action' => 'updated', 'count' => $updated, 'target_code' => $code];
            }
        }

        return new JsonResponse($results);
    }
}
