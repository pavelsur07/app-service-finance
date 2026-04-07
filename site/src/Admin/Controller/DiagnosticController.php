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

    #[Route('/fix-wb-costs-categories', name: 'fix_wb_costs', methods: ['GET'])]
    public function fixWbCostsCategories(Connection $connection): JsonResponse
    {
        $companyId = '19621cff-b028-45d9-9193-11f47ad9a8b2';

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
}
