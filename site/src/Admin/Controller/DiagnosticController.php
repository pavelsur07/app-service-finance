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
}
