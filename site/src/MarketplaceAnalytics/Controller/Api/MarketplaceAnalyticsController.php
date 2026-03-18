<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Request\CreateMarketplaceAnalyticsRequest;
use App\MarketplaceAnalytics\Application\CreateMarketplaceAnalyticsAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

final class MarketplaceAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly CreateMarketplaceAnalyticsAction $createAction
    ) {}

    #[Route('/api/marketplaceanalytics', name: 'api_marketplaceanalytics_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] CreateMarketplaceAnalyticsRequest $request
    ): JsonResponse {
        // Вызов Application слоя
        ($this->createAction)($request);

        return $this->json(['status' => 'success'], 201);
    }
}
