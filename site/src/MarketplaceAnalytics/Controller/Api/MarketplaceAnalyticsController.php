<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Request\CreateMarketplaceAnalyticsRequest;
use App\MarketplaceAnalytics\Application\CreateMarketplaceAnalyticsAction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_OWNER')]
final class MarketplaceAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CreateMarketplaceAnalyticsAction $createAction,
    ) {}

    #[Route('/api/marketplaceanalytics', name: 'api_marketplaceanalytics_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] CreateMarketplaceAnalyticsRequest $request,
    ): JsonResponse {
        $this->activeCompanyService->getActiveCompany();

        ($this->createAction)($request);

        return $this->json(['status' => 'success'], 201);
    }
}
