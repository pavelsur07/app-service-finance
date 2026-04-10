<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Company-level aggregation of sales revenue and return refunds for a period.
 *
 * Used by RunUserReconciliationAction to enrich reconciliation results
 * with data from marketplace_sales and marketplace_returns tables.
 */
final class SalesReturnsTotalQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * SUM(total_revenue) from marketplace_sales for the period.
     *
     * @return string bcmath-compatible string, e.g. '5298086.00'
     */
    public function getSalesTotal(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): string {
        $result = $this->connection->fetchOne(
            <<<'SQL'
            SELECT COALESCE(SUM(s.total_revenue), 0)
            FROM marketplace_sales s
            WHERE s.company_id  = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date  >= :periodFrom
              AND s.sale_date  <= :periodTo
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        return (string) $result;
    }

    /**
     * SUM(refund_amount) from marketplace_returns for the period.
     *
     * @return string bcmath-compatible string, e.g. '20006.00'
     */
    public function getReturnsTotal(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): string {
        $result = $this->connection->fetchOne(
            <<<'SQL'
            SELECT COALESCE(SUM(r.refund_amount), 0)
            FROM marketplace_returns r
            WHERE r.company_id  = :companyId
              AND r.marketplace = :marketplace
              AND r.return_date >= :periodFrom
              AND r.return_date <= :periodTo
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        return (string) $result;
    }
}
