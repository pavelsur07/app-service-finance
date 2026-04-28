<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Помечает записи Marketplace как обработанные:
 * SET document_id = :documentId WHERE document_id IS NULL AND date BETWEEN ...
 *
 * Выполняется ПОСЛЕ успешного создания документа ОПиУ через FinanceFacade.
 * Bulk UPDATE — не загружает Entity в память.
 */
final class MarkProcessedQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Пометить продажи как обработанные.
     *
     * @return int Количество обновлённых записей
     */
    public function markSales(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
        bool $preliminary = false,
    ): int {
        $marketplaceEnum = MarketplaceType::from($marketplace);
        $saleGrossExpr = AmountSource::SALE_GROSS->getSqlExpression($marketplaceEnum);
        $amountCase = <<<SQL
            CASE m.amount_source
                WHEN 'sale_gross'      THEN $saleGrossExpr
                WHEN 'sale_revenue'    THEN s.total_revenue
                WHEN 'sale_cost_price' THEN COALESCE(s.cost_price, 0) * s.quantity
                ELSE 0
            END
        SQL;

        $preliminaryFilter = $preliminary
            ? 'AND s.cost_price IS NOT NULL AND s.cost_price > 0'
            : '';

        return $this->connection->executeStatement(
            'UPDATE marketplace_sales
             SET document_id = :documentId,
                 updated_at = NOW()
             WHERE id IN (
                SELECT s.id
                FROM marketplace_sales s
                WHERE s.company_id = :companyId
                  AND s.marketplace = :marketplace
                  AND s.document_id IS NULL
                  AND s.sale_date >= :periodFrom
                  AND s.sale_date <= :periodTo
                  ' . $preliminaryFilter . '
                  AND EXISTS (
                      SELECT 1
                      FROM marketplace_sale_mappings m
                      WHERE m.company_id = s.company_id
                        AND m.marketplace = s.marketplace
                        AND m.operation_type = \'sale\'
                        AND m.is_active = true
                        AND ' . $amountCase . ' != 0
                  )
             )',
            [
                'documentId' => $documentId,
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ]
        );
    }

    /**
     * Пометить возвраты как обработанные.
     *
     * @return int Количество обновлённых записей
     */
    public function markReturns(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
        bool $preliminary = false,
    ): int {
        $preliminaryFilter = $preliminary
            ? 'AND r.cost_price IS NOT NULL AND r.cost_price > 0'
            : '';

        return $this->connection->executeStatement(
            'UPDATE marketplace_returns
             SET document_id = :documentId,
                 updated_at = NOW()
             WHERE id IN (
                SELECT r.id
                FROM marketplace_returns r
                LEFT JOIN marketplace_sales ms
                    ON ms.id = r.sale_id
                WHERE r.company_id = :companyId
                  AND r.marketplace = :marketplace
                  AND r.document_id IS NULL
                  AND r.return_date >= :periodFrom
                  AND r.return_date <= :periodTo
                  ' . $preliminaryFilter . '
                  AND EXISTS (
                      SELECT 1
                      FROM marketplace_sale_mappings m
                      WHERE m.company_id = r.company_id
                        AND m.marketplace = r.marketplace
                        AND m.operation_type = \'return\'
                        AND m.amount_source != \'return_realization\'
                        AND m.is_active = true
                        AND CASE m.amount_source
                            WHEN \'return_refund\' THEN COALESCE(r.refund_amount, 0)
                            WHEN \'return_gross\' THEN COALESCE(ms.price_per_unit * r.quantity, r.refund_amount, 0)
                            WHEN \'return_cost_price\' THEN COALESCE(r.cost_price * r.quantity, 0)
                            ELSE 0
                        END != 0
                  )
             )',
            [
                'documentId' => $documentId,
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ]
        );
    }

    /**
     * Пометить расходы как обработанные.
     *
     * @return int Количество обновлённых записей
     */
    public function markCosts(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
        bool $preliminary = false,
    ): int {
        $preliminaryFilter = $preliminary
            ? "AND mcc2.code != 'ozon_other_service'"
            : '';

        return $this->connection->executeStatement(
            'UPDATE marketplace_costs
             SET document_id = :documentId,
                 updated_at = NOW()
             WHERE id IN (
                 SELECT c.id
                 FROM marketplace_costs c
                 INNER JOIN marketplace_cost_categories mcc
                     ON mcc.id = c.category_id
                 INNER JOIN marketplace_cost_pl_mappings m
                     ON m.cost_category_id = c.category_id
                     AND m.company_id = c.company_id
                 WHERE c.company_id = :companyId
                   AND c.marketplace = :marketplace
                   AND c.document_id IS NULL
                   AND c.cost_date >= :periodFrom
                   AND c.cost_date <= :periodTo
                   AND m.include_in_pl = true
                   AND m.pl_category_id IS NOT NULL
                   AND EXISTS (
                       SELECT 1
                       FROM marketplace_costs c2
                       INNER JOIN marketplace_cost_categories mcc2
                           ON mcc2.id = c2.category_id
                       INNER JOIN marketplace_cost_pl_mappings m2
                           ON m2.cost_category_id = c2.category_id
                           AND m2.company_id = c2.company_id
                       WHERE c2.company_id = c.company_id
                         AND c2.marketplace = c.marketplace
                         AND c2.document_id IS NULL
                         AND c2.cost_date >= :periodFrom
                         AND c2.cost_date <= :periodTo
                         AND c2.category_id = c.category_id
                         AND (c2.operation_type = \'storno\') = (c.operation_type = \'storno\')
                         AND m2.include_in_pl = true
                         AND m2.pl_category_id IS NOT NULL
                         ' . $preliminaryFilter . '
                       GROUP BY
                           m2.pl_category_id,
                           m2.sort_order,
                           m2.is_negative,
                           (c2.operation_type = \'storno\')
                       HAVING ABS(SUM(c2.amount)) > 0.001
                   )
             )',
            [
                'documentId' => $documentId,
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ]
        );
    }

    /**
     * @param string[] $documentIds
     */
    public function unmarkSalesByDocumentIds(
        string $companyId,
        string $marketplace,
        array $documentIds,
    ): int {
        if ($documentIds === []) {
            return 0;
        }

        return $this->unmarkByDocumentIds(
            table: 'marketplace_sales',
            companyId: $companyId,
            marketplace: $marketplace,
            documentIds: $documentIds,
        );
    }

    /**
     * @param string[] $documentIds
     */
    public function unmarkReturnsByDocumentIds(
        string $companyId,
        string $marketplace,
        array $documentIds,
    ): int {
        if ($documentIds === []) {
            return 0;
        }

        return $this->unmarkByDocumentIds(
            table: 'marketplace_returns',
            companyId: $companyId,
            marketplace: $marketplace,
            documentIds: $documentIds,
        );
    }

    /**
     * @param string[] $documentIds
     */
    public function unmarkCostsByDocumentIds(
        string $companyId,
        string $marketplace,
        array $documentIds,
    ): int {
        if ($documentIds === []) {
            return 0;
        }

        return $this->unmarkByDocumentIds(
            table: 'marketplace_costs',
            companyId: $companyId,
            marketplace: $marketplace,
            documentIds: $documentIds,
        );
    }

    public function unmarkSalesByPeriod(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->unmarkByPeriod(
            table: 'marketplace_sales',
            dateColumn: 'sale_date',
            companyId: $companyId,
            marketplace: $marketplace,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
        );
    }

    public function unmarkReturnsByPeriod(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->unmarkByPeriod(
            table: 'marketplace_returns',
            dateColumn: 'return_date',
            companyId: $companyId,
            marketplace: $marketplace,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
        );
    }

    public function unmarkCostsByPeriod(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->unmarkByPeriod(
            table: 'marketplace_costs',
            dateColumn: 'cost_date',
            companyId: $companyId,
            marketplace: $marketplace,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
        );
    }

    /**
     * @param string[] $documentIds
     */
    private function unmarkByDocumentIds(
        string $table,
        string $companyId,
        string $marketplace,
        array $documentIds,
    ): int {
        return $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET document_id = NULL,
                     updated_at = NOW()
                 WHERE company_id = :companyId
                   AND marketplace = :marketplace
                   AND document_id IN (:documentIds)',
                $table,
            ),
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'documentIds' => $documentIds,
            ],
            [
                'documentIds' => ArrayParameterType::STRING,
            ],
        );
    }

    private function unmarkByPeriod(
        string $table,
        string $dateColumn,
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET document_id = NULL,
                     updated_at = NOW()
                 WHERE company_id = :companyId
                   AND marketplace = :marketplace
                   AND %s >= :periodFrom
                   AND %s <= :periodTo
                   AND document_id IS NOT NULL',
                $table,
                $dateColumn,
                $dateColumn,
            ),
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ],
        );
    }
}
