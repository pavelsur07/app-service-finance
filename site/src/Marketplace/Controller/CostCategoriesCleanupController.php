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
 * ВРЕМЕННЫЙ контроллер для очистки неиспользуемых категорий затрат.
 *
 * После перехода на атомарный маппинг SERVICE_CATEGORY_MAP старые grouped категории
 * (ozon_logistics, ozon_promotion, ozon_subscription и т.д.) остались в справочнике
 * но больше не используются ни одной записью marketplace_costs.
 *
 * Использование:
 *   GET  /marketplace/costs/admin/cleanup-categories          → предпросмотр
 *   GET  /marketplace/costs/admin/cleanup-categories?confirm=1 → удаление
 *
 * Удаляет ТОЛЬКО категории без привязанных затрат (document_id проверяется через costs).
 * После удаления — удалить этот контроллер.
 */
#[Route('/marketplace/costs/admin')]
#[IsGranted('ROLE_USER')]
final class CostCategoriesCleanupController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly Connection           $connection,
    ) {
    }

    #[Route('/cleanup-categories', name: 'marketplace_costs_cleanup_categories', methods: ['GET', 'POST'])]
    public function cleanup(Request $request): JsonResponse
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $confirm   = $request->isMethod('POST')
            && $request->request->get('confirm', '0') === '1';

        // Находим категории без единой привязанной записи затрат
        $unused = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                cc.id,
                cc.code,
                cc.name,
                cc.created_at::text AS created_at
            FROM marketplace_cost_categories cc
            WHERE cc.company_id = :companyId
              AND NOT EXISTS (
                  SELECT 1 FROM marketplace_costs c
                  WHERE c.category_id = cc.id
              )
            ORDER BY cc.created_at
            SQL,
            ['companyId' => $companyId],
        );

        if (!$confirm) {
            return $this->json([
                'action'    => 'preview',
                'to_delete' => count($unused),
                'items'     => array_map(static fn (array $r) => [
                    'code'       => $r['code'],
                    'name'       => $r['name'],
                    'created_at' => $r['created_at'],
                ], $unused),
                'next_step' => 'POST /marketplace/costs/admin/cleanup-categories with confirm=1&_token=<csrf_token>',
            ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
        }

        if (!$this->isCsrfTokenValid('cleanup_categories', $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        // Удаляем
        $deleted = 0;
        foreach ($unused as $row) {
            $this->connection->executeStatement(
                'DELETE FROM marketplace_cost_categories WHERE id = :id',
                ['id' => $row['id']],
            );
            $deleted++;
        }

        return $this->json([
            'action'  => 'deleted',
            'deleted' => $deleted,
            'items'   => array_map(static fn (array $r) => $r['code'], $unused),
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }
}
