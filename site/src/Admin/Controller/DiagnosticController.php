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
                mc.id,
                mc.company_id,
                mc.cost_date,
                mc.description,
                mc.amount,
                mc.raw_document_id,
                mrd.marketplace
             FROM marketplace_costs mc
             LEFT JOIN marketplace_raw_documents mrd ON mc.raw_document_id = mrd.id
             WHERE mc.cost_category_id IS NULL
             ORDER BY mc.cost_date DESC
             LIMIT 50'
        );

        return new JsonResponse($rows);
    }
}
