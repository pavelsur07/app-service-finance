<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Application\LoadMutualSettlementAction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт для ручной загрузки отчёта «Взаиморасчёты» из Ozon API.
 *
 * Загружает raw-данные за указанный месяц и сохраняет в marketplace_raw_documents.
 * Парсинг и маппинг в marketplace_costs НЕ выполняется — только сохранение raw.
 *
 * Использование:
 *   POST /api/marketplace-analytics/debug/load-mutual-settlement?year=2026&month=1
 */
#[Route(
    path: '/api/marketplace-analytics/debug/load-mutual-settlement',
    name: 'api_marketplace_analytics_debug_load_mutual_settlement',
    methods: ['POST'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugLoadMutualSettlementController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly LoadMutualSettlementAction $loadAction,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $year = $request->query->getInt('year', 0);
        $month = $request->query->getInt('month', 0);

        if ($year < 2020 || $year > 2030) {
            return $this->json(['error' => 'year is required (2020-2030)'], 422);
        }

        if ($month < 1 || $month > 12) {
            return $this->json(['error' => 'month is required (1-12)'], 422);
        }

        $company = $this->activeCompanyService->getActiveCompany();

        $periodFrom = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $periodTo = $periodFrom->modify('last day of this month');

        try {
            $result = ($this->loadAction)($company, $periodFrom, $periodTo);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        $responseSize = $result['responseSize'];
        $formattedSize = $responseSize >= 1024
            ? round($responseSize / 1024, 1) . 'KB'
            : $responseSize . 'B';

        return $this->json([
            'success' => true,
            'rawDocumentId' => $result['rawDocumentId'],
            'period' => [
                'from' => $periodFrom->format('Y-m-d'),
                'to' => $periodTo->format('Y-m-d'),
            ],
            'recordsCount' => $result['recordsCount'],
            'responseSize' => $formattedSize,
        ]);
    }
}
