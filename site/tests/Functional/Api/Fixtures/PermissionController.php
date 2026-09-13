<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Fixtures;

use App\Api\Security\ApiAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Imported exclusively from the test environment route and service configuration. */
final class PermissionController
{
    #[Route('/api/external/v1/__test/permissions/accounts.read', name: 'test_api_permission_accounts_read', methods: ['GET'])]
    #[ApiAccess('accounts.read')]
    public function accounts_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/counterparties.read', name: 'test_api_permission_counterparties_read', methods: ['GET'])]
    #[ApiAccess('counterparties.read')]
    public function counterparties_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/cash_categories.read', name: 'test_api_permission_cash_categories_read', methods: ['GET'])]
    #[ApiAccess('cash_categories.read')]
    public function cash_categories_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/pl_categories.read', name: 'test_api_permission_pl_categories_read', methods: ['GET'])]
    #[ApiAccess('pl_categories.read')]
    public function pl_categories_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/projects.read', name: 'test_api_permission_projects_read', methods: ['GET'])]
    #[ApiAccess('projects.read')]
    public function projects_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/responsibility_centers.read', name: 'test_api_permission_responsibility_centers_read', methods: ['GET'])]
    #[ApiAccess('responsibility_centers.read')]
    public function responsibility_centers_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/cash_transactions.read', name: 'test_api_permission_cash_transactions_read', methods: ['GET'])]
    #[ApiAccess('cash_transactions.read')]
    public function cash_transactions_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/cash_transactions.create', name: 'test_api_permission_cash_transactions_create', methods: ['GET'])]
    #[ApiAccess('cash_transactions.create')]
    public function cash_transactions_create(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/cash_transactions.update', name: 'test_api_permission_cash_transactions_update', methods: ['GET'])]
    #[ApiAccess('cash_transactions.update')]
    public function cash_transactions_update(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/cash_transactions.soft_delete', name: 'test_api_permission_cash_transactions_soft_delete', methods: ['GET'])]
    #[ApiAccess('cash_transactions.soft_delete')]
    public function cash_transactions_soft_delete(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/pl_operations.read', name: 'test_api_permission_pl_operations_read', methods: ['GET'])]
    #[ApiAccess('pl_operations.read')]
    public function pl_operations_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/pl_operations.create', name: 'test_api_permission_pl_operations_create', methods: ['GET'])]
    #[ApiAccess('pl_operations.create')]
    public function pl_operations_create(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/pl_operations.update', name: 'test_api_permission_pl_operations_update', methods: ['GET'])]
    #[ApiAccess('pl_operations.update')]
    public function pl_operations_update(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/pl_operations.soft_delete', name: 'test_api_permission_pl_operations_soft_delete', methods: ['GET'])]
    #[ApiAccess('pl_operations.soft_delete')]
    public function pl_operations_soft_delete(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/cashflow_reports.read', name: 'test_api_permission_cashflow_reports_read', methods: ['GET'])]
    #[ApiAccess('cashflow_reports.read')]
    public function cashflow_reports_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/pl_reports.read', name: 'test_api_permission_pl_reports_read', methods: ['GET'])]
    #[ApiAccess('pl_reports.read')]
    public function pl_reports_read(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/missing', name: 'test_api_permission_missing', methods: ['GET'])]
    public function missing(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }

    #[Route('/api/external/v1/__test/permissions/unknown', name: 'test_api_permission_unknown', methods: ['GET'])]
    #[ApiAccess('unknown.read')]
    public function unknown(): JsonResponse
    {
        return new JsonResponse(['allowed' => true]);
    }
}
