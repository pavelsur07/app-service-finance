<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Infrastructure\Query\PortfolioSummaryQuery;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitEconomicsQuery;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace-analytics/unit-economics', name: 'marketplace_analytics_api_unit_economics', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class UnitEconomicsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly UnitEconomicsQuery $unitEconomicsQuery,
        private readonly PortfolioSummaryQuery $portfolioSummaryQuery,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

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

        $dateFrom = $request->query->get(
            'date_from',
            (new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
        );
        $dateTo = $request->query->get(
            'date_to',
            (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
        );

        if ($dateFrom > $dateTo) {
            return $this->json(['error' => 'date_from must be <= date_to.'], 422);
        }

        $page = max(1, $request->query->getInt('page', 1));

        $qb      = $this->unitEconomicsQuery->buildQueryBuilder($companyId, $marketplace, $dateFrom, $dateTo);
        $adapter = new QueryAdapter($qb, static function (QueryBuilder $qb): void {
            $qb->select('COUNT(DISTINCT s.listing_id)')
                ->resetGroupBy()
                ->resetHaving()
                ->resetOrderBy();
        });

        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage($adapter, $page, 50);

        $summary = $this->portfolioSummaryQuery->fetch($companyId, $marketplace, $dateFrom, $dateTo);

        return $this->json([
            'data' => iterator_to_array($pager->getCurrentPageResults()),
            'summary' => $summary,
            'meta' => [
                'total' => $pager->getNbResults(),
                'page' => $pager->getCurrentPage(),
                'per_page' => $pager->getMaxPerPage(),
                'pages' => $pager->getNbPages(),
            ],
        ]);
    }
}
