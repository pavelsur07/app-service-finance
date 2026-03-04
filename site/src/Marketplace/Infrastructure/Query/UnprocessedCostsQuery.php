<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Агрегирует необработанные расходы маркетплейса по категориям.
 *
 * Маппинг: MarketplaceCost → MarketplaceCostCategory.pl_category_id (существующий 1:1).
 * Только записи с настроенным pl_category_id включаются.
 *
 * WHERE document_id IS NULL — только необработанные записи.
 */
final class UnprocessedCostsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{
     *     pl_category_id: string,
     *     total_amount: string,
     *     description: string
     * }>
     */
    public function execute(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $sql = <<<'SQL'
            SELECT
                mcc.pl_category_id,
                SUM(c.amount) AS total_amount,
                mcc.name AS description
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories mcc
                ON mcc.id = c.category_id
            WHERE c.company_id = :companyId
                AND c.marketplace = :marketplace
                AND c.document_id IS NULL
                AND c.cost_date >= :periodFrom
                AND c.cost_date <= :periodTo
                AND mcc.pl_category_id IS NOT NULL
            GROUP BY
                mcc.pl_category_id,
                mcc.name
            HAVING SUM(c.amount) != 0
            ORDER BY mcc.name ASC
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
        ]);
    }
}
