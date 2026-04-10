<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Экспорт результата сверки в xlsx.
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationExportController extends AbstractController
{
    #[Route('/api/marketplace/reconciliation/{id}/export', name: 'api_marketplace_reconciliation_export', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        // TODO: P1 — реализовать экспорт результата сверки в xlsx
        return new JsonResponse(['error' => 'Export not implemented yet'], 501);
    }
}
