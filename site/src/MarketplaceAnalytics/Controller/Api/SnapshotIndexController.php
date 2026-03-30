<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Api\Request\ListSnapshotsRequest;
use App\MarketplaceAnalytics\Api\Response\SnapshotResponse;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SnapshotIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ListingDailySnapshotRepositoryInterface $snapshotRepository,
        private readonly MarketplaceFacade $marketplaceFacade,
    ) {}

    #[Route(
        '/api/marketplace-analytics/snapshots',
        name: 'marketplace_analytics_api_snapshot_index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $req = ListSnapshotsRequest::fromRequest($request);

        if ($req->marketplace === null || $req->marketplace === '') {
            return $this->json(
                ['type' => 'BAD_REQUEST', 'message' => 'Параметр marketplace обязателен'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $dateFrom = $req->dateFrom !== null ? new \DateTimeImmutable($req->dateFrom) : null;
            $dateTo = $req->dateTo !== null ? new \DateTimeImmutable($req->dateTo) : null;
        } catch (\Exception) {
            return $this->json(
                ['type' => 'BAD_REQUEST', 'message' => 'Неверный формат даты. Используйте YYYY-MM-DD.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $result = $this->snapshotRepository->findPaginated(
            $company->getId(),
            $req->marketplace,
            $dateFrom,
            $dateTo,
            $req->listingId,
            $req->page,
            $req->perPage,
        );

        $listingMap = [];
        if ($result['items'] !== []) {
            $listings = $this->marketplaceFacade->getActiveListings($company->getId(), $req->marketplace);
            foreach ($listings as $listing) {
                $listingMap[$listing->id] = $listing;
            }
        }

        $data = array_map(
            static fn($snapshot) => SnapshotResponse::fromEntity(
                $snapshot,
                $listingMap[$snapshot->getListingId()]->name ?? '',
                $listingMap[$snapshot->getListingId()]->marketplaceSku ?? '',
            )->toArray(),
            $result['items'],
        );

        $total = $result['total'];
        $pages = $req->perPage > 0 ? (int) ceil($total / $req->perPage) : 1;

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $req->page,
                'per_page' => $req->perPage,
                'pages' => $pages,
            ],
        ]);
    }
}
