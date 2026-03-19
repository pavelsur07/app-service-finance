<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MonthCloseDebugQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-страница сверки данных «Закрытие месяца».
 *
 * Форматы:
 *   GET /marketplace/month-close/debug       → HTML (агрегат + lazy детализация)
 *   GET /marketplace/month-close/debug/json  → JSON полный дамп (скачать / Postman)
 *   GET /marketplace/month-close/debug/rows  → JSON страница строк (AJAX по клику)
 *
 * Параметры:
 *   marketplace  ozon | wildberries | ...
 *   year         int
 *   month        int
 *   processed    '' → все  |  '0' → необработанные  |  '1' → обработанные
 *   document_id  UUID PLDocument — только записи этого документа
 *   source       sales | returns | realization  (только /rows)
 *   page         int  (только /rows)
 */
#[Route('/marketplace/month-close/debug')]
#[IsGranted('ROLE_USER')]
final class MonthCloseDebugController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly MonthCloseDebugQuery $debugQuery,
    ) {
    }

    // -------------------------------------------------------------------------
    // HTML
    // -------------------------------------------------------------------------

    #[Route('', name: 'marketplace_month_close_debug', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [
            $companyId, $marketplace, $year, $month,
            $periodFrom, $periodTo, $processed, $documentId,
        ] = $this->resolveParams($request);

        $salesReturns = $this->debugQuery->aggregateSalesReturns(
            $companyId, $marketplace, $periodFrom, $periodTo, $processed, $documentId,
        );

        $realization = [];
        if ($marketplace === MarketplaceType::OZON->value) {
            $realization = $this->debugQuery->aggregateRealization(
                $companyId, $periodFrom, $periodTo, $processed, $documentId,
            );
        }

        return $this->render('@Marketplace/month_close/debug.html.twig', [
            'active_tab'             => 'month_close',
            'marketplace'            => $marketplace,
            'available_marketplaces' => MarketplaceType::cases(),
            'year'                   => $year,
            'month'                  => $month,
            'processed'              => $processed,
            'document_id'            => $documentId,
            'sales_returns'          => $salesReturns,
            'realization'            => $realization,
        ]);
    }

    // -------------------------------------------------------------------------
    // JSON: полный дамп
    // -------------------------------------------------------------------------

    #[Route('/json', name: 'marketplace_month_close_debug_json', methods: ['GET'])]
    public function dumpJson(Request $request): JsonResponse
    {
        [
            $companyId, $marketplace, $year, $month,
            $periodFrom, $periodTo, $processed, $documentId,
        ] = $this->resolveParams($request);

        $salesReturns  = $this->debugQuery->aggregateSalesReturns($companyId, $marketplace, $periodFrom, $periodTo, $processed, $documentId);
        $salesDetail   = $this->debugQuery->detailSales($companyId, $marketplace, $periodFrom, $periodTo, $processed, $documentId);
        $returnsDetail = $this->debugQuery->detailReturns($companyId, $marketplace, $periodFrom, $periodTo, $processed, $documentId);

        $realization       = [];
        $realizationDetail = [];
        if ($marketplace === MarketplaceType::OZON->value) {
            $realization       = $this->debugQuery->aggregateRealization($companyId, $periodFrom, $periodTo, $processed, $documentId);
            $realizationDetail = $this->debugQuery->detailRealization($companyId, $periodFrom, $periodTo, $processed, $documentId);
        }

        return $this->json([
            'meta' => [
                'company_id'  => $companyId,
                'marketplace' => $marketplace,
                'period_from' => $periodFrom,
                'period_to'   => $periodTo,
                'processed'   => $processed,
                'document_id' => $documentId,
            ],
            'aggregate' => [
                'sales_returns' => $salesReturns,
                'realization'   => $realization,
            ],
            'detail' => [
                'sales'       => $salesDetail,
                'returns'     => $returnsDetail,
                'realization' => $realizationDetail,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // JSON: AJAX-детализация (lazy по клику на строку агрегата)
    // -------------------------------------------------------------------------

    #[Route('/rows', name: 'marketplace_month_close_debug_rows', methods: ['GET'])]
    public function rows(Request $request): JsonResponse
    {
        [
            $companyId, $marketplace, $year, $month,
            $periodFrom, $periodTo, $processed, $documentId,
        ] = $this->resolveParams($request);

        $source = $request->query->get('source', 'sales');
        $page   = max(1, (int) $request->query->get('page', 1));

        $result = match ($source) {
            'sales'       => $this->debugQuery->detailSales($companyId, $marketplace, $periodFrom, $periodTo, $processed, $documentId, $page),
            'returns'     => $this->debugQuery->detailReturns($companyId, $marketplace, $periodFrom, $periodTo, $processed, $documentId, $page),
            'realization' => $this->debugQuery->detailRealization($companyId, $periodFrom, $periodTo, $processed, $documentId, $page),
            default       => throw $this->createNotFoundException("Unknown source: {$source}"),
        };

        return $this->json($result);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * @return array{string, string, int, int, string, string, ?bool, ?string}
     */
    private function resolveParams(Request $request): array
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace = $request->query->get('marketplace') ?: MarketplaceType::OZON->value;
        $year        = (int) $request->query->get('year', date('Y'));
        $month       = (int) $request->query->get('month', date('n'));

        if (MarketplaceType::tryFrom($marketplace) === null) {
            $marketplace = MarketplaceType::OZON->value;
        }

        $periodFrom = sprintf('%d-%02d-01', $year, $month);
        $periodTo   = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        $processed = match ($request->query->get('processed', '')) {
            '0'     => false,
            '1'     => true,
            default => null,
        };

        $documentId = $request->query->get('document_id') ?: null;

        return [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo, $processed, $documentId];
    }
}
