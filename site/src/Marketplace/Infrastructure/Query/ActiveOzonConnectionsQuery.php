<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL Query: получение активных Ozon-подключений для ежедневной синхронизации.
 * Возвращает массивы (не Entity) — Fast Read по правилам проекта.
 */
final class ActiveOzonConnectionsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{id: string, company_id: string}>
     */
    public function execute(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT mc.id, mc.company_id
             FROM marketplace_connections mc
             WHERE mc.marketplace = :marketplace
               AND mc.is_active = true
             ORDER BY mc.created_at ASC',
            ['marketplace' => 'ozon'],
        );
    }
}
