<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Api\Response\SnapshotResponse;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SnapshotShowController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ListingDailySnapshotRepositoryInterface $snapshotRepository,
        private readonly MarketplaceFacade $marketplaceFacade,
    ) {}

    #[Route(
        '/api/marketplace-analytics/snapshots/{id}',
        name: 'marketplace_analytics_api_snapshot_show',
        methods: ['GET'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $snapshot = $this->snapshotRepository->findById($id, $company->getId());

        if ($snapshot === null) {
            return $this->json(
                ['type' => 'NOT_FOUND', 'message' => 'Снимок не найден'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $listing = $this->marketplaceFacade->findListingById(
            $company->getId(),
            $snapshot->getListingId(),
        );

        return $this->json(
            SnapshotResponse::fromEntity(
                $snapshot,
                $listing?->name ?? '',
                $listing?->marketplaceSku ?? '',
            )->toArray(),
        );
    }
}
