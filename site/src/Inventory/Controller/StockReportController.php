<?php

declare(strict_types=1);

namespace App\Inventory\Controller;

use App\Inventory\Infrastructure\Query\InventoryStockReportQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class StockReportController extends AbstractController
{
    /** Источники, для которых нормализуются остатки (см. NormalizeInventorySnapshotAction). */
    private const SOURCES = [MarketplaceType::OZON, MarketplaceType::WILDBERRIES];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly InventoryStockReportQuery $stockReportQuery,
    ) {
    }

    #[Route('/inventory/stocks', name: 'inventory_stocks_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        $source = $this->parseSource($request->query->all()['source'] ?? null);
        $date = $this->parseDate($request->query->all()['date'] ?? null) ?? new \DateTimeImmutable('today');

        $effectiveDate = $this->stockReportQuery->findEffectiveSnapshotDate($companyId, $source, $date);

        $pager = $this->stockReportQuery->getPage(
            companyId: $companyId,
            page: max(1, $request->query->getInt('page', 1)),
            perPage: InventoryStockReportQuery::PER_PAGE,
            source: $source,
            snapshotDate: $effectiveDate ?? $date,
        );

        return $this->render('inventory/stocks/index.html.twig', [
            'pager' => $pager,
            'source' => $source,
            'sources' => self::SOURCES,
            'date' => $date,
            'effectiveDate' => $effectiveDate,
        ]);
    }

    private function parseSource(mixed $raw): MarketplaceType
    {
        $source = is_string($raw) ? MarketplaceType::tryFrom($raw) : null;

        return null !== $source && in_array($source, self::SOURCES, true) ? $source : MarketplaceType::OZON;
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        // Формат проверяется до парсера: createFromFormat() бросает ValueError на null-байт,
        // а год 0000 PostgreSQL DATE не принимает и роняет запрос.
        if (!is_string($raw) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}\z/', $raw) || str_starts_with($raw, '0000-')) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        // Обратное форматирование отсекает несуществующие дни вроде 2026-02-31.
        return false !== $date && $date->format('Y-m-d') === $raw ? $date : null;
    }
}
