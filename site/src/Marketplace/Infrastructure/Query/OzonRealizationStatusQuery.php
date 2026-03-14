<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Проверяет наличие загруженных realization-документов Ozon для компании.
 * Используется в контроллере для определения стратегии загрузки:
 *   - нет документов → первичная загрузка с начала года
 *   - есть документы → загрузить только прошлый месяц
 */
final class OzonRealizationStatusQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Есть ли хоть один realization-документ Ozon для компании?
     */
    public function hasAny(string $companyId): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_documents
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND document_type = :documentType
             LIMIT 1',
            [
                'companyId'    => $companyId,
                'marketplace'  => 'ozon',
                'documentType' => 'realization',
            ],
        );

        return (int) $count > 0;
    }

    /**
     * Список уже загруженных месяцев в формате ['2026-01', '2026-02'].
     * Используется чтобы не дублировать загрузку.
     */
    public function loadedMonths(string $companyId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT TO_CHAR(period_from, 'YYYY-MM') AS month
             FROM marketplace_raw_documents
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND document_type = :documentType
             ORDER BY period_from ASC",
            [
                'companyId'    => $companyId,
                'marketplace'  => 'ozon',
                'documentType' => 'realization',
            ],
        );

        return array_column($rows, 'month');
    }
}
