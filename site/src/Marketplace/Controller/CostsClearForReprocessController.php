<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ВРЕМЕННЫЙ контроллер для очистки затрат Ozon перед переобработкой.
 *
 * Нужен однократно: старые записи marketplace_costs созданы без raw_document_id,
 * поэтому автоматический DELETE в OzonCostsRawProcessor::process() их не удаляет.
 *
 * После переобработки всех периодов — удалить этот контроллер.
 *
 * Использование (два шага):
 *
 *   Шаг 1 — предпросмотр (ничего не удаляет):
 *   GET /marketplace/costs/admin/clear-for-reprocess?marketplace=ozon&year=2026&month=1
 *
 *   Шаг 2 — удаление (требует confirm=1):
 *   POST /marketplace/costs/admin/clear-for-reprocess?marketplace=ozon&year=2026&month=1&confirm=1
 */
#[Route('/marketplace/costs/admin')]
#[IsGranted('ROLE_USER')]
final class CostsClearForReprocessController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly Connection           $connection,
    ) {
    }

    /**
     * GET  → предпросмотр: сколько записей будет удалено, без удаления.
     * POST → удаление после confirm=1.
     */
    #[Route('/clear-for-reprocess', name: 'marketplace_costs_clear_for_reprocess', methods: ['GET'])]
    public function clearForReprocess(Request $request): JsonResponse
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace', 'ozon');
        $year        = (int) $request->query->get('year', date('Y'));
        $month       = (int) $request->query->get('month', date('n'));
        $confirm     = $request->query->get('confirm', '0') === '1';

        $periodFrom = sprintf('%d-%02d-01', $year, $month);
        $periodTo   = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        // Считаем сколько записей попадает под удаление
        $count = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
            FROM marketplace_costs
            WHERE company_id      = :companyId
              AND marketplace     = :marketplace
              AND cost_date      >= :periodFrom
              AND cost_date      <= :periodTo
              AND document_id    IS NULL
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        // GET или confirm не передан — только предпросмотр
        if (!$confirm) {
            return $this->json([
                'action'      => 'preview',
                'marketplace' => $marketplace,
                'period'      => "{$periodFrom} – {$periodTo}",
                'to_delete'   => $count,
                'protected'   => 'Записи с document_id (закрытые в ОПиУ) не затрагиваются',
                'next_step'   => "POST /marketplace/costs/admin/clear-for-reprocess?marketplace={$marketplace}&year={$year}&month={$month}&confirm=1",
            ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
        }

        // POST + confirm=1 — удаляем
        $deleted = $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_costs
            WHERE company_id      = :companyId
              AND marketplace     = :marketplace
              AND cost_date      >= :periodFrom
              AND cost_date      <= :periodTo
              AND document_id    IS NULL
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        return $this->json([
            'action'      => 'deleted',
            'marketplace' => $marketplace,
            'period'      => "{$periodFrom} – {$periodTo}",
            'deleted'     => $deleted,
            'next_step'   => 'Запусти переобработку затрат за этот период через UI или CLI',
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }
}
