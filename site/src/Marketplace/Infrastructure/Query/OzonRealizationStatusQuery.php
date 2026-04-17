<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final class OzonRealizationStatusQuery
{
    public function __construct(private readonly Connection $connection)
    {
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
