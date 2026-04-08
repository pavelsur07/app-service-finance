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
}
