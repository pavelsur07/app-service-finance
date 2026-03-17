<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL-запросы для preflight проверок этапа SALES_RETURNS.
 *
 * Проверяет:
 *   - количество продаж за период
 *   - количество продаж без себестоимости (блокирующее)
 *   - количество возвратов за период
 *   - количество возвратов без себестоимости (блокирующее)
 *   - загружена ли реализация Ozon за месяц (предупреждение)
 */
final class PreflightSalesReturnsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getSalesStats(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE cost_price IS NULL OR cost_price = 0) AS without_cost,
                COUNT(*) FILTER (WHERE document_id IS NOT NULL) AS already_processed
             FROM marketplace_sales
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND sale_date >= :periodFrom
               AND sale_date <= :periodTo',
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        ) ?: ['total' => 0, 'without_cost' => 0, 'already_processed' => 0];
    }

    public function getReturnsStats(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE cost_price IS NULL OR cost_price = 0) AS without_cost,
                COUNT(*) FILTER (WHERE document_id IS NOT NULL) AS already_processed
             FROM marketplace_returns
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND return_date >= :periodFrom
               AND return_date <= :periodTo',
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        ) ?: ['total' => 0, 'without_cost' => 0, 'already_processed' => 0];
    }

    /**
     * Проверить загружена ли реализация Ozon за месяц.
     * Реализация загружается помесячно — проверяем наличие документа типа 'realization'
     * с periodFrom = первый день месяца.
     */
    public function isOzonRealizationLoaded(
        string $companyId,
        int $year,
        int $month,
    ): bool {
        $periodFrom = sprintf('%d-%02d-01', $year, $month);

        $result = $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM marketplace_raw_documents
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND document_type = :documentType
               AND period_from = :periodFrom',
            [
                'companyId'    => $companyId,
                'marketplace'  => 'ozon',
                'documentType' => 'realization',
                'periodFrom'   => $periodFrom,
            ],
        );

        return (int) $result > 0;
    }
}
