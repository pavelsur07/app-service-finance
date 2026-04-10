<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\Reconciliation\OzonXlsxServiceGroupMap;
use App\Marketplace\Repository\ReconciliationSessionRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TEMPORARY: debug-эндпоинт для диагностики расхождений по категориям.
 *
 * Возвращает детализацию marketplace_costs по category_code за период сессии,
 * сгруппированную по serviceGroup из OzonXlsxServiceGroupMap.
 *
 * Удалить после завершения отладки сверки.
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationCategoryBreakdownController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ReconciliationSessionRepository $sessionRepository,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        '/api/marketplace/reconciliation/debug/category-breakdown',
        name: 'api_marketplace_reconciliation_debug_category_breakdown',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $payload   = json_decode($request->getContent(), true) ?? [];
        $sessionId = (string) ($payload['sessionId'] ?? '');

        if ($sessionId === '') {
            return $this->json(['error' => 'sessionId обязателен.'], 400);
        }

        $session = $this->sessionRepository->findByIdAndCompany($sessionId, $companyId);
        if ($session === null) {
            return $this->json(['error' => 'Сессия не найдена.'], 404);
        }

        $periodFrom = $session->getPeriodFrom()->format('Y-m-d');
        $periodTo   = $session->getPeriodTo()->format('Y-m-d');
        $marketplace = $session->getMarketplace();

        // Суммы по каждому category_code
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                cc.code                                                    AS category_code,
                cc.name                                                    AS category_name,
                SUM(c.amount)                                              AS net_amount,
                SUM(CASE WHEN c.amount > 0 THEN c.amount  ELSE 0 END)     AS costs_amount,
                SUM(CASE WHEN c.amount < 0 THEN ABS(c.amount) ELSE 0 END) AS storno_amount
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            GROUP BY cc.code, cc.name
            ORDER BY ABS(SUM(c.amount)) DESC
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        $categoryToGroup = OzonXlsxServiceGroupMap::getCategoryToServiceGroup();

        // 1. categories — плоский список
        $categories = [];
        foreach ($rows as $row) {
            $categories[] = [
                'category_code' => $row['category_code'],
                'category_name' => $row['category_name'],
                'service_group' => $categoryToGroup[$row['category_code']] ?? null,
                'net_amount'    => round((float) $row['net_amount'], 2),
                'costs_amount'  => round((float) $row['costs_amount'], 2),
                'storno_amount' => round((float) $row['storno_amount'], 2),
            ];
        }

        // 2. by_service_group — сгруппировано
        $grouped = [];
        foreach ($categories as $cat) {
            $group = $cat['service_group'] ?? 'Не определена';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [
                    'service_group' => $group,
                    'net_amount'    => 0.0,
                    'costs_amount'  => 0.0,
                    'storno_amount' => 0.0,
                    'categories'    => [],
                ];
            }
            $grouped[$group]['net_amount']    = round($grouped[$group]['net_amount'] + $cat['net_amount'], 2);
            $grouped[$group]['costs_amount']  = round($grouped[$group]['costs_amount'] + $cat['costs_amount'], 2);
            $grouped[$group]['storno_amount'] = round($grouped[$group]['storno_amount'] + $cat['storno_amount'], 2);
            $grouped[$group]['categories'][]  = $cat;
        }
        // Sort by abs net descending
        $byServiceGroup = array_values($grouped);
        usort($byServiceGroup, static fn (array $a, array $b) => abs($b['net_amount']) <=> abs($a['net_amount']));

        // 3. unmapped — категории без маппинга в OzonXlsxServiceGroupMap
        $unmapped = array_values(array_filter(
            $categories,
            static fn (array $c) => $c['service_group'] === null,
        ));

        return $this->json([
            'categories'       => $categories,
            'by_service_group' => $byServiceGroup,
            'unmapped'         => $unmapped,
        ]);
    }
}
