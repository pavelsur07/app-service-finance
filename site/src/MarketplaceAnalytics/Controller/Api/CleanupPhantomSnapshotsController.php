<?php

// TODO: Удалить после использования — одноразовый инструмент для очистки фантомных снапшотов.

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace-analytics/snapshots/cleanup-phantom',
    name: 'marketplace_analytics_api_cleanup_phantom_snapshots',
    methods: ['POST'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class CleanupPhantomSnapshotsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $body = json_decode($request->getContent(), true) ?? [];

        $marketplace = $body['marketplace'] ?? '';
        if (MarketplaceType::tryFrom($marketplace) === null) {
            return $this->json([
                'type'    => 'VALIDATION_ERROR',
                'message' => 'Неверный маркетплейс',
            ], 422);
        }

        $dryRun = (bool) ($body['dry_run'] ?? true);

        if ($dryRun) {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM listing_daily_snapshots
                 WHERE company_id = :companyId AND marketplace = :marketplace',
                ['companyId' => $companyId, 'marketplace' => $marketplace],
            );

            return $this->json([
                'dry_run' => true,
                'count'   => $count,
                'message' => sprintf('Будет удалено %d снапшотов', $count),
            ]);
        }

        $deleted = (int) $this->connection->executeStatement(
            'DELETE FROM listing_daily_snapshots
             WHERE company_id = :companyId AND marketplace = :marketplace',
            ['companyId' => $companyId, 'marketplace' => $marketplace],
        );

        return $this->json([
            'dry_run' => false,
            'deleted' => $deleted,
            'message' => sprintf('Удалено %d снапшотов', $deleted),
        ]);
    }
}
