<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TEMPORARY — удалить после проверки миграции OVH.
 *
 * Проверяет что категория ozon_ovh_processing создана и записи мигрированы.
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationOvhCheckController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        '/api/marketplace/reconciliation/debug/ovh-check',
        name: 'api_marketplace_reconciliation_debug_ovh_check',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        // 1. category_exists
        $categoryRow = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT id, code, name
            FROM marketplace_cost_categories
            WHERE code = 'ozon_ovh_processing'
              AND company_id = :companyId
            SQL,
            ['companyId' => $companyId],
        );

        $categoryExists = $categoryRow ?: null;

        // 2. records_with_new_code
        if ($categoryExists !== null) {
            $count = (int) $this->connection->fetchOne(
                <<<'SQL'
                SELECT COUNT(*)
                FROM marketplace_costs
                WHERE category_id = :categoryId
                  AND company_id = :companyId
                SQL,
                [
                    'categoryId' => $categoryExists['id'],
                    'companyId'  => $companyId,
                ],
            );
            $recordsWithNewCode = ['count' => $count];
        } else {
            $recordsWithNewCode = ['count' => 0, 'error' => 'category not found'];
        }

        // 3. raw_data_sample — записи ozon_supply_additional за январь 2026
        $supplyAdditionalId = $this->connection->fetchOne(
            <<<'SQL'
            SELECT id
            FROM marketplace_cost_categories
            WHERE code = 'ozon_supply_additional'
              AND company_id = :companyId
            SQL,
            ['companyId' => $companyId],
        );

        $rawDataSample = [];
        if ($supplyAdditionalId !== false) {
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                SELECT c.id, c.cost_date::text AS cost_date, c.amount, c.raw_data
                FROM marketplace_costs c
                WHERE c.category_id = :categoryId
                  AND c.company_id = :companyId
                  AND c.cost_date >= '2026-01-01'
                  AND c.cost_date <= '2026-01-31'
                LIMIT 10
                SQL,
                [
                    'categoryId' => $supplyAdditionalId,
                    'companyId'  => $companyId,
                ],
            );

            foreach ($rows as $i => $row) {
                $rawData = json_decode($row['raw_data'] ?? '{}', true);

                $entry = [
                    'id'        => $row['id'],
                    'cost_date' => $row['cost_date'],
                    'amount'    => $row['amount'],
                ];

                if ($i < 3) {
                    // Первые 3 — полный JSON
                    $entry['raw_data'] = $rawData;
                } else {
                    // Остальные — только ключи type/name/service_name
                    $keys = ['type', 'name', 'service_name', 'operation_type', 'operation_type_name'];
                    $summary = [];
                    foreach ($keys as $key) {
                        if (isset($rawData[$key])) {
                            $summary[$key] = $rawData[$key];
                        }
                    }
                    $entry['raw_data_keys'] = $summary;
                }

                $rawDataSample[] = $entry;
            }
        }

        return $this->json([
            'category_exists'       => $categoryExists,
            'records_with_new_code' => $recordsWithNewCode,
            'raw_data_sample'       => $rawDataSample,
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }
}
