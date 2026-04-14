<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Infrastructure\Query\WidgetSummaryQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/api/marketplace-analytics/unit-extended/widgets',
    name: 'api_marketplace_analytics_widgets_summary',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class GetWidgetsSummaryController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly WidgetSummaryQuery $widgetQuery,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $marketplace = $request->query->get('marketplace') ?: null;
        $periodFrom = new \DateTimeImmutable($request->query->getString('periodFrom'));
        $periodTo = new \DateTimeImmutable($request->query->getString('periodTo'));

        $current = $this->widgetQuery->getSummary(
            $company->getId(),
            $marketplace,
            $periodFrom,
            $periodTo,
        );

        $days = $periodFrom->diff($periodTo)->days;
        $prevTo = $periodFrom->modify('-1 day');
        $prevFrom = $prevTo->modify("-{$days} days");

        $previous = $this->widgetQuery->getSummary(
            $company->getId(),
            $marketplace,
            $prevFrom,
            $prevTo,
        );

        return new JsonResponse([
            'current'  => $current,
            'previous' => $previous,
            'period'   => [
                'from'         => $periodFrom->format('Y-m-d'),
                'to'           => $periodTo->format('Y-m-d'),
                'previousFrom' => $prevFrom->format('Y-m-d'),
                'previousTo'   => $prevTo->format('Y-m-d'),
            ],
        ]);
    }
}
