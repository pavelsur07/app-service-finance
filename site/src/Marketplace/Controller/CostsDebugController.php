<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\CostsVerifyQuery;
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
        private readonly ActiveCompanyService $companyService,
        private readonly CostsVerifyQuery     $verifyQuery,
    ) {
    }

    /**
     * Компактный JSON для ручной сверки с «Детализацией начислений» Ozon.
     *
     * Скопируй результат и сравни с xlsx-отчётом из ЛК Ozon.
     */
    #[Route('/verify', name: 'marketplace_costs_debug_verify', methods: ['GET'])]
    public function verify(Request $request): JsonResponse
    {
        [$companyId, $marketplace, $year, $month, $periodFrom, $periodTo] = $this->resolveParams($request);

        $data = $this->verifyQuery->run($companyId, $marketplace, $periodFrom, $periodTo);

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
