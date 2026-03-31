<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Request\SnapshotSummaryRequest;
use App\MarketplaceAnalytics\Api\Response\SnapshotSummaryResponse;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\Facade\MarketplaceAnalyticsFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SnapshotSummaryController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceAnalyticsFacade $facade,
    ) {}

    #[Route(
        '/api/marketplace-analytics/snapshots/summary',
        name: 'marketplace_analytics_api_snapshot_summary',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $req = SnapshotSummaryRequest::fromRequest($request);

        $marketplace = ($req->marketplace !== null && $req->marketplace !== '')
            ? $req->marketplace
            : null;

        if ($req->dateFrom === null || $req->dateTo === null) {
            return $this->json(
                ['type' => 'BAD_REQUEST', 'message' => 'Параметры dateFrom и dateTo обязательны'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $period = AnalysisPeriod::custom(
                new \DateTimeImmutable($req->dateFrom),
                new \DateTimeImmutable($req->dateTo),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(
                ['type' => 'BAD_REQUEST', 'message' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $items = $this->facade->getUnitEconomics(
            $company->getId(),
            $period,
            $marketplace,
        );

        $response = SnapshotSummaryResponse::fromUnitEconomics($period, $items);

        return $this->json($response->toArray());
    }
}
