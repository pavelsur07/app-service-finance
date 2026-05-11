<?php

declare(strict_types=1);

namespace App\Inventory\Controller;

use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Infrastructure\Query\InventoryStockReportQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class StockReportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly InventoryStockReportQuery $stockReportQuery,
    ) {
    }

    #[Route('/inventory/stocks', name: 'inventory_stocks_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        $source = MarketplaceType::tryFrom((string) $request->query->get('source', MarketplaceType::OZON->value)) ?? MarketplaceType::OZON;
        $snapshotSessionId = $request->query->getString('snapshotSessionId');
        $snapshotSessionId = '' !== $snapshotSessionId && Uuid::isValid($snapshotSessionId) ? $snapshotSessionId : null;

        $snapshotAt = $request->query->getString('snapshotAt');
        $snapshotAtDt = null;
        if ('' !== $snapshotAt) {
            try {
                $snapshotAtDt = new \DateTimeImmutable($snapshotAt);
            } catch (\Throwable) {
                $snapshotAtDt = null;
            }
        }

        $mappingStatusValue = $request->query->getString('mappingStatus');
        $mappingStatus = '' !== $mappingStatusValue ? StockSnapshotMappingStatus::tryFrom($mappingStatusValue) : null;

        if ($snapshotSessionId === null && $snapshotAtDt === null) {
            $snapshotSessionId = $this->stockReportQuery->findLatestSnapshotSessionId($companyId, $source);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $pager = $this->stockReportQuery->getPage(
            companyId: $companyId,
            page: $page,
            perPage: InventoryStockReportQuery::PER_PAGE,
            source: $source,
            snapshotSessionId: $snapshotSessionId,
            snapshotAt: $snapshotAtDt,
            search: $request->query->getString('search'),
            mappingStatus: $mappingStatus,
        );

        return $this->render('inventory/stocks/index.html.twig', [
            'pager' => $pager,
            'source' => $source,
            'sources' => MarketplaceType::cases(),
            'mappingStatuses' => StockSnapshotMappingStatus::cases(),
            'filters' => [
                'snapshotSessionId' => $snapshotSessionId,
                'snapshotAt' => $snapshotAt,
                'search' => $request->query->getString('search'),
                'mappingStatus' => $mappingStatus?->value,
            ],
        ]);
    }
}
