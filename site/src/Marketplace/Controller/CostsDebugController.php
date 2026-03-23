<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\CostsVerifyQuery;
use App\Marketplace\Infrastructure\Query\RawOperationsAnalysisQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-эндпоинт сверки затрат Ozon.
 *
 * Использование:
 *   GET /marketplace/costs/debug/verify?marketplace=ozon&year=2026&month=3
 *
 * Как сверять:
 *   1. Открыть Ozon Seller → Финансы → Детализация начислений
 *   2. Скачать .xlsx за тот же период
 *   3. Сравнить итоги по каждой категории с полем totals_by_category
 *   4. Сравнить grand_total с итоговой суммой в xlsx
 */
#[Route('/marketplace/costs/debug')]
#[IsGranted('ROLE_USER')]
final class CostsDebugController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService        $companyService,
        private readonly CostsVerifyQuery            $verifyQuery,
        private readonly RawOperationsAnalysisQuery  $rawOperationsQuery,
    ) {
    }

    /**
     * Компактный JSON для ручной сверки с «Детализацией начислений» Ozon.
     *
     * Скопируй результат и сравни с xlsx-отчётом из ЛК Ozon.
     *
     * Параметры:
     *   ?xlsx_total=3761721.62 — итог колонки «Сумма» из xlsx (только расходные группы).
     *                            Если передан — period_health.reconciliation покажет OK или MISMATCH.
     */
    #[Route('/verify', name: 'marketplace_costs_debug_verify', methods: ['GET'])]
    public function verify(Request $request): JsonResponse
    {
        [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo] = $this->resolveParams($request);

        $xlsxTotal = $request->query->get('xlsx_total') !== null
            ? (float) $request->query->get('xlsx_total')
            : null;

        $data = $this->verifyQuery->run($companyId, $marketplace, $periodFrom, $periodTo, $xlsxTotal);

        return $this->json([
            'meta' => [
                'marketplace'  => $marketplace,
                'period'       => "{$periodFrom} – {$periodTo}",
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            'checks' => $data,
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * Анализ operation_type и service_name из raw-документов за период.
     *
     * Используй для диагностики расхождений между нашим grand_total и xlsx.
     * Показывает реальное распределение operation_type / service_name → category_code.
     *
     * GET /marketplace/costs/debug/raw-operations?marketplace=ozon&year=2026&month=2
     * GET /marketplace/costs/debug/raw-operations?marketplace=ozon&year=2026&month=2&category=ozon_logistic_direct
     */
    #[Route('/raw-operations', name: 'marketplace_costs_debug_raw_operations', methods: ['GET'])]
    public function rawOperations(Request $request): JsonResponse
    {
        [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo] = $this->resolveParams($request);

        $filterCategory = $request->query->get('category') ?: null;

        $data = $this->rawOperationsQuery->run(
            $companyId,
            $marketplace,
            $periodFrom,
            $periodTo,
            $filterCategory,
        );

        return $this->json([
            'meta' => [
                'marketplace'     => $marketplace,
                'period'          => "{$periodFrom} – {$periodTo}",
                'filter_category' => $filterCategory,
                'generated_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            'data' => $data,
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * Версия задеплоенного OzonServiceCategoryMap.
     * Используй для проверки что нужный маппинг попал на прод.
     *
     * GET /marketplace/costs/debug/map-version
     */
    #[Route('/map-version', name: 'marketplace_costs_debug_map_version', methods: ['GET'])]
    public function mapVersion(): JsonResponse
    {
        return $this->json([
            'map' => OzonServiceCategoryMap::getMapStats(),
            'hint' => 'Сравни version с последним коммитом в OzonServiceCategoryMap',
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{string, string, int, int, string, string}
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

        return [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo];
    }
}
