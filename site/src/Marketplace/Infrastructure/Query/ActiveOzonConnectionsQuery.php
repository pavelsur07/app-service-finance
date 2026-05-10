<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceConnectionType;
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
     * @return array<int, array{id: string, company_id: string, client_id: null|string, finance_lock_before: null|string}>
     */
    public function execute(?string $companyId = null): array
    {
        $sql = 'SELECT mc.id, mc.company_id, mc.client_id, c.finance_lock_before
                FROM marketplace_connections mc
                INNER JOIN companies c ON c.id = mc.company_id
                WHERE mc.marketplace = :marketplace
                  AND mc.connection_type = :connection_type
                  AND mc.is_active = true';

        $params = [
            'marketplace' => 'ozon',
            'connection_type' => MarketplaceConnectionType::SELLER->value,
        ];

        if (null !== $companyId) {
            $sql .= ' AND mc.company_id = :company_id';
            $params['company_id'] = $companyId;
        }

        $sql .= ' ORDER BY mc.created_at ASC, mc.id ASC';

        return $this->connection->fetchAllAssociative($sql, $params);
    }
}
