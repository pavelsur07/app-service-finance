<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
}
