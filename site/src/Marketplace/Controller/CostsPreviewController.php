<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\PreflightCostsQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debug-контроллер для предварительного просмотра затрат перед закрытием месяца.
 *
 * Показывает что именно войдёт в PLDocument при закрытии этапа COSTS.
 * Используй ПЕРЕД закрытием для проверки корректности данных.
 *
 * GET /marketplace/month-close/costs-preview?marketplace=ozon&year=2026&month=1
 */
#[Route('/marketplace/month-close')]
#[IsGranted('ROLE_USER')]
final class CostsPreviewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly UnprocessedCostsQuery $unprocessedCostsQuery,
        private readonly PreflightCostsQuery   $preflightCostsQuery,
    ) {
    }

    /**
     * Полный предпросмотр затрат которые войдут в PLDocument.
     *
     * Возвращает:
     *   - summary: итоговые суммы и количество строк документа
     *   - pl_document_lines: каждая строка будущего PLDocument
     *   - category_breakdown: детализация по категориям затрат (включая исключённые)
     *   - control_sum: контрольная сумма для проверки
     *
     * GET /marketplace/month-close/costs-preview?marketplace=ozon&year=2026&month=2
     */
    #[Route('/costs-preview', name: 'marketplace_month_close_costs_preview', methods: ['GET'])]
    public function preview(Request $request): JsonResponse
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace') ?: MarketplaceType::OZON->value;
        $year        = (int) $request->query->get('year', date('Y'));
        $month       = (int) $request->query->get('month', date('n'));

        if (MarketplaceType::tryFrom($marketplace) === null) {
            $marketplace = MarketplaceType::OZON->value;
        }

        $periodFrom = sprintf('%d-%02d-01', $year, $month);
        $periodTo   = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        // Строки которые войдут в PLDocument
        $entries = $this->unprocessedCostsQuery->execute($companyId, $marketplace, $periodFrom, $periodTo);

        // Контрольная сумма
        $controlSum = $this->unprocessedCostsQuery->getControlSum($companyId, $marketplace, $periodFrom, $periodTo);

        // Полная детализация по категориям (включая исключённые)
        $breakdown = $this->preflightCostsQuery->getCostsCategoryBreakdown($companyId, $marketplace, $periodFrom, $periodTo);

        // Статистика
        $costsStats = $this->preflightCostsQuery->getCostsStats($companyId, $marketplace, $periodFrom, $periodTo);

        // Суммаризация строк PLDocument
        $totalCostsAmount  = '0';
        $totalStornoAmount = '0';
        foreach ($entries as $entry) {
            if ($entry['is_storno']) {
                $totalStornoAmount = bcadd($totalStornoAmount, $entry['total_amount'], 2);
            } else {
                $totalCostsAmount = bcadd($totalCostsAmount, $entry['total_amount'], 2);
            }
        }

        return $this->json([
            'meta' => [
                'marketplace'  => $marketplace,
                'period'       => "{$periodFrom} – {$periodTo}",
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'hint'         => 'Это предпросмотр PLDocument который будет создан при закрытии этапа COSTS',
            ],
            'summary' => [
                'total_records'          => (int) $costsStats['total'],
                'already_processed'      => (int) $costsStats['already_processed'],
                'without_pl_mapping'     => (int) $costsStats['without_pl_mapping'],
                'excluded_from_pl'       => (int) $costsStats['excluded_from_pl'],
                'pl_document_lines'      => count($entries),
                'costs_lines'            => count(array_filter($entries, fn($e) => !$e['is_storno'])),
                'storno_lines'           => count(array_filter($entries, fn($e) => $e['is_storno'])),
                'costs_amount'           => number_format((float) $totalCostsAmount, 2, '.', ' '),
                'storno_amount'          => number_format((float) $totalStornoAmount, 2, '.', ' '),
                'control_sum_net'        => number_format((float) $controlSum, 2, '.', ' '),
                'net_amount_for_pl'      => number_format((float) $costsStats['net_amount_for_pl'], 2, '.', ' '),
            ],
            'pl_document_lines' => array_map(static fn (array $e) => [
                'cost_category_code' => $e['cost_category_code'],
                'cost_category_name' => $e['cost_category_name'],
                'pl_category_id'     => $e['pl_category_id'],
                'is_storno'          => $e['is_storno'],
                'costs_amount'       => number_format((float) $e['costs_amount'], 2, '.', ' '),
                'storno_amount'      => number_format((float) $e['storno_amount'], 2, '.', ' '),
                'total_amount'       => number_format((float) $e['total_amount'], 2, '.', ' '),
                'is_negative'        => $e['is_negative'],
                'description'        => $e['description'],
                'sort_order'         => $e['sort_order'],
                'records_count'      => $e['records_count'],
            ], $entries),
            'category_breakdown' => array_map(static fn (array $r) => [
                'category_code'      => $r['category_code'],
                'category_name'      => $r['category_name'],
                'pl_category_id'     => $r['pl_category_id'],
                'include_in_pl'      => (bool) $r['include_in_pl'],
                'is_negative'        => (bool) $r['is_negative'],
                'count'              => (int) $r['count'],
                'costs_amount'       => number_format((float) $r['costs_amount'], 2, '.', ' '),
                'storno_amount'      => number_format((float) $r['storno_amount'], 2, '.', ' '),
                'net_amount'         => number_format((float) $r['net_amount'], 2, '.', ' '),
                'already_processed'  => (int) $r['already_processed'],
                'status'             => match (true) {
                    $r['pl_category_id'] === null                    => 'no_mapping',
                    !(bool) $r['include_in_pl']                      => 'excluded',
                    (int) $r['already_processed'] > 0                => 'already_processed',
                    default                                          => 'will_be_closed',
                },
            ], $breakdown),
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }
}
