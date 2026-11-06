<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller;

use App\MarketplaceAnalytics\Application\StockTurnoverFilterParser;
use App\MarketplaceAnalytics\Infrastructure\Export\StockTurnoverXlsxExporter;
use App\MarketplaceAnalytics\Infrastructure\Query\StockTurnoverQuery;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\RateLimiter\ReportsApiRateLimiter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Экспорт отчёта «Оборачиваемость» с теми же параметрами, что у страницы. */
#[IsGranted('ROLE_COMPANY_USER')]
final class StockTurnoverExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly StockTurnoverFilterParser $filterParser,
        private readonly StockTurnoverQuery $query,
        private readonly StockTurnoverXlsxExporter $exporter,
        private readonly ReportsApiRateLimiter $rateLimiter,
    ) {
    }

    #[Route('/inventory/stock-turnover/export.xlsx', name: 'inventory_stock_turnover_export', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        if (!$this->rateLimiter->consume($companyId)) {
            return new JsonResponse(['error' => ['code' => 'too_many_requests', 'message' => 'Слишком много запросов, повторите позже']], 429);
        }

        $filter = $this->filterParser->parse($request, $companyId);
        $report = $this->query->execute($filter);
        $exporter = $this->exporter;

        $response = new StreamedResponse(static function () use ($exporter, $report): void {
            $tmpFile = tempnam(sys_get_temp_dir(), 'stock_turnover_');
            if (false === $tmpFile) {
                throw new \RuntimeException('Unable to create temporary file for export');
            }
            try {
                $exporter->export($report, $tmpFile);
                readfile($tmpFile);
            } finally {
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="stock-turnover-%s-%dd.xlsx"', $filter->date->format('Y-m-d'), $filter->periodDays));
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
