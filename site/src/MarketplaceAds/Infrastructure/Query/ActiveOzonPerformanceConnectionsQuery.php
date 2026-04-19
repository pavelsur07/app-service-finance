<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL Query: идентификаторы компаний с активным Ozon Performance подключением.
 *
 * Используется в cron-команде ежедневной загрузки Ozon Ads, чтобы пройтись
 * ровно по тем компаниям, у которых настроен Performance API.
 */
final class ActiveOzonPerformanceConnectionsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getCompanyIds(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT company_id
             FROM marketplace_connections
             WHERE marketplace = :marketplace
               AND connection_type = :connectionType
               AND is_active = true
             ORDER BY created_at ASC',
            [
                'marketplace' => 'ozon',
                'connectionType' => 'performance',
            ],
        );

        return array_values(array_map(static fn ($id): string => (string) $id, $rows));
    }
}
