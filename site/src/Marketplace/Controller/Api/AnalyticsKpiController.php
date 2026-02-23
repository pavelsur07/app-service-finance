<?php

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\AnalyticsKpiQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API эндпоинт для получения KPI метрик аналитики
 */
#[Route('/api/marketplace/analytics')]
#[IsGranted('ROLE_USER')]
class AnalyticsKpiController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsKpiQuery $kpiQuery,
        private readonly ActiveCompanyService $activeCompanyService
    ) {
    }

    /**
     * Получить все KPI метрики за период
     *
     * Query params:
     * - from: дата начала периода (Y-m-d)
     * - to: дата окончания периода (Y-m-d)
     * - marketplace: фильтр по маркетплейсу (опционально: wildberries|ozon)
     *
     * Response:
     * {
     *   "current": {
     *     "revenue": "4250000.00",
     *     "margin": "1120000.00",
     *     "units_sold": 3420,
     *     "roi": 187.0,
     *     "return_rate": 8.2,
     *     "turnover_days": 42,
     *     "currency": "RUB"
     *   },
     *   "previous": {
     *     "revenue": "3800000.00",
     *     "margin": "980000.00",
     *     "units_sold": 3100
     *   }
     * }
     */
    #[Route('/kpi', name: 'api_marketplace_analytics_kpi', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        // Валидация и парсинг параметров
        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');
        $marketplaceStr = $request->query->get('marketplace');

        if (!$fromStr || !$toStr) {
            throw new BadRequestHttpException('Параметры "from" и "to" обязательны');
        }

        try {
            $from = new \DateTimeImmutable($fromStr);
            $to = new \DateTimeImmutable($toStr);
        } catch (\Exception $e) {
            throw new BadRequestHttpException('Неверный формат даты. Используйте Y-m-d (например: 2026-01-01)');
        }

        // Парсим marketplace (опционально)
        $marketplace = null;
        if ($marketplaceStr && $marketplaceStr !== 'all') {
            $marketplace = MarketplaceType::tryFrom($marketplaceStr);
            if (!$marketplace) {
                throw new BadRequestHttpException('Неверное значение marketplace. Допустимые: wildberries, ozon');
            }
        }

        // Получаем данные через Query (Read операция - без Application слоя)
        $data = $this->kpiQuery->getAllKpi(
            $company->getId(),
            $marketplace,
            $from,
            $to
        );

        return $this->json($data);
    }
}
