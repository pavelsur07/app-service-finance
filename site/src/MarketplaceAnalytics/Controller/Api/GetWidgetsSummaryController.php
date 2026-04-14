<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
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

        $marketplace = $request->query->get('marketplace');
        if ($marketplace === null || $marketplace === '') {
            $marketplace = null;
        } else {
            $validValues = array_map(
                static fn (MarketplaceType $t): string => $t->value,
                MarketplaceType::cases(),
            );
            if (!in_array($marketplace, $validValues, true)) {
                return $this->json([
                    'error' => 'Invalid marketplace. Allowed: ' . implode(', ', $validValues),
                ], 422);
            }
        }

        $periodFromStr = $request->query->get('periodFrom', '');
        $periodToStr   = $request->query->get('periodTo', '');

        if ($periodFromStr === '' || $periodToStr === '') {
            return $this->json(['error' => 'periodFrom and periodTo are required'], 422);
        }

        try {
            $periodFrom = new \DateTimeImmutable($periodFromStr);
            $periodTo   = new \DateTimeImmutable($periodToStr);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date format. Expected Y-m-d'], 422);
        }

        if ($periodFrom > $periodTo) {
            return $this->json(['error' => 'periodFrom must be <= periodTo'], 422);
        }

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
