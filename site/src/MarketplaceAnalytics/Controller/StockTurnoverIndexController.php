<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller;

use App\Inventory\Enum\FulfillmentScheme;
use App\Marketplace\DTO\ListingTagDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\ListingTagFacade;
use App\MarketplaceAnalytics\Application\StockTurnoverFilterParser;
use App\MarketplaceAnalytics\DTO\StockTurnoverFilter;
use App\MarketplaceAnalytics\Enum\TurnoverBucket;
use App\MarketplaceAnalytics\Infrastructure\Query\StockTurnoverQuery;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Отчёт «Оборачиваемость»: где заморожены деньги в остатках. Живёт во вкладках «Товары и остатки», модуль — MarketplaceAnalytics (сборка из Facade). */
#[IsGranted('ROLE_COMPANY_USER')]
final class StockTurnoverIndexController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly StockTurnoverFilterParser $filterParser,
        private readonly StockTurnoverQuery $query,
        private readonly ListingTagFacade $listingTagFacade,
    ) {
    }

    #[Route('/inventory/stock-turnover', name: 'inventory_stock_turnover_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $filter = $this->filterParser->parse($request, $companyId);
        $report = $this->query->execute($filter);

        // Отчёт строится в памяти целиком (сводка нужна по всем строкам), поэтому ArrayAdapter.
        $pager = new Pagerfanta(new ArrayAdapter($report->rows));
        $pager->setMaxPerPage(self::PER_PAGE);
        try {
            $pager->setCurrentPage(StockTurnoverFilterParser::page($request));
        } catch (OutOfRangeCurrentPageException) {
            // Закладка на страницу, которой после изменения остатков или фильтра больше нет: фильтры сохраняем, страница первая.
            return $this->redirectToRoute('inventory_stock_turnover_index', array_diff_key($request->query->all(), ['page' => true]));
        }

        return $this->render('marketplace_analytics/stock_turnover/index.html.twig', [
            'pager' => $pager,
            'summary' => $report->summary,
            'filter' => $filter,
            'periods' => StockTurnoverFilter::PERIODS,
            'sources' => MarketplaceType::cases(),
            'schemes' => FulfillmentScheme::cases(),
            'buckets' => TurnoverBucket::cases(),
            'tags' => array_map(static fn (ListingTagDTO $tag): array => $tag->toArray(), $this->listingTagFacade->list($companyId)),
        ]);
    }
}
