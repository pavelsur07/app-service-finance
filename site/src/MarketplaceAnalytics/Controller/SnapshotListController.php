<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Infrastructure\Query\SnapshotListQuery;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class SnapshotListController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly SnapshotListQuery $snapshotListQuery,
    ) {
    }

    #[Route(
        '/marketplace-analytics/snapshots',
        name: 'marketplace_analytics_snapshots_index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace = $request->query->get('marketplace') ?: null;
        $dateFrom = $request->query->get('date_from') ?: null;
        $dateTo = $request->query->get('date_to') ?: null;
        $listingId = $request->query->get('listing_id') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        if ($marketplace !== null && MarketplaceType::tryFrom($marketplace) === null) {
            $marketplace = null;
        }

        $qb = $this->snapshotListQuery->buildQueryBuilder(
            $companyId,
            $marketplace,
            $dateFrom,
            $dateTo,
            $listingId,
        );

        $adapter = new QueryAdapter($qb, static function (QueryBuilder $qb): void {
            $qb->select('COUNT(s.id)')
                ->resetOrderBy();
        });

        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage($adapter, $page, 50);

        return $this->render('marketplace_analytics/snapshots/index.html.twig', [
            'pager' => $pager,
            'available_marketplaces' => MarketplaceType::cases(),
            'filters' => [
                'marketplace' => $marketplace,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'listing_id' => $listingId,
            ],
        ]);
    }
}
