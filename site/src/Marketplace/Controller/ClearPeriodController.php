<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ВРЕМЕННЫЙ контроллер для очистки продаж/возвратов/затрат за период.
 *
 * После переобработки raw-документов появились дубли — этот контроллер удаляет
 * обработанные записи за период, чтобы потом переобработать чисто.
 *
 * НЕ трогает raw-документы и записи, закрытые в ОПиУ (document_id IS NOT NULL).
 *
 * После завершения переобработки — удалить этот контроллер.
 *
 * Шаг 1 — предпросмотр (ничего не удаляет):
 *   GET /marketplace/test/clear-period?marketplace=ozon&year=2026&month=4
 *
 * Шаг 2 — удаление (требует confirm=1):
 *   GET /marketplace/test/clear-period?marketplace=ozon&year=2026&month=4&confirm=1
 */
#[Route('/marketplace/test')]
#[IsGranted('ROLE_USER')]
final class ClearPeriodController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly Connection           $connection,
        private readonly LoggerInterface      $logger,
    ) {
    }

    #[Route('/clear-period', name: 'marketplace_test_clear_period', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace', 'ozon');
        $year        = (int) $request->query->get('year', date('Y'));
        $month       = (int) $request->query->get('month', date('n'));
        $confirm     = $request->query->get('confirm', '0') === '1';

        $dateFrom    = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $dateTo      = $dateFrom->modify('last day of this month');
        $periodFrom  = $dateFrom->format('Y-m-d');
        $periodTo    = $dateTo->format('Y-m-d');
        $periodLabel = sprintf('%s — %s', $periodFrom, $periodTo);

        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ];

        if (!$confirm) {
            return $this->json([
                'preview'     => true,
                'marketplace' => $marketplace,
                'period'      => $periodLabel,
                'willDelete'  => [
                    'sales'   => $this->countSales($params),
                    'returns' => $this->countReturns($params),
                    'costs'   => $this->countCosts($params),
                ],
                'warning' => 'Добавьте &confirm=1 для удаления',
            ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
        }

        // Удаляем в порядке: возвраты → продажи → затраты
        // (marketplace_returns.sale_id имеет ON DELETE SET NULL на marketplace_sales)
        $deletedReturns = $this->deleteReturns($params);
        $deletedSales   = $this->deleteSales($params);
        $deletedCosts   = $this->deleteCosts($params);

        $this->logger->warning('ClearPeriodController: удалены данные за период', [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'period'      => $periodLabel,
            'deleted'     => [
                'sales'   => $deletedSales,
                'returns' => $deletedReturns,
                'costs'   => $deletedCosts,
            ],
        ]);

        return $this->json([
            'preview'     => false,
            'marketplace' => $marketplace,
            'period'      => $periodLabel,
            'deleted'     => [
                'sales'   => $deletedSales,
                'returns' => $deletedReturns,
                'costs'   => $deletedCosts,
            ],
        ], 200, [], ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE]);
    }

    /** @param array<string, string> $params */
    private function countSales(array $params): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
            FROM marketplace_sales
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND sale_date   >= :periodFrom
              AND sale_date   <= :periodTo
              AND document_id IS NULL
            SQL,
            $params,
        );
    }

    /** @param array<string, string> $params */
    private function countReturns(array $params): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
            FROM marketplace_returns
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND return_date >= :periodFrom
              AND return_date <= :periodTo
              AND document_id IS NULL
            SQL,
            $params,
        );
    }

    /** @param array<string, string> $params */
    private function countCosts(array $params): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
            FROM marketplace_costs
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND cost_date   >= :periodFrom
              AND cost_date   <= :periodTo
              AND document_id IS NULL
            SQL,
            $params,
        );
    }

    /** @param array<string, string> $params */
    private function deleteSales(array $params): int
    {
        return (int) $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_sales
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND sale_date   >= :periodFrom
              AND sale_date   <= :periodTo
              AND document_id IS NULL
            SQL,
            $params,
        );
    }

    /** @param array<string, string> $params */
    private function deleteReturns(array $params): int
    {
        return (int) $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_returns
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND return_date >= :periodFrom
              AND return_date <= :periodTo
              AND document_id IS NULL
            SQL,
            $params,
        );
    }

    /** @param array<string, string> $params */
    private function deleteCosts(array $params): int
    {
        return (int) $this->connection->executeStatement(
            <<<'SQL'
            DELETE FROM marketplace_costs
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND cost_date   >= :periodFrom
              AND cost_date   <= :periodTo
              AND document_id IS NULL
            SQL,
            $params,
        );
    }
}
