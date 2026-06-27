<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

/**
 * DBAL Query: active Ozon Performance connections for ingestion orchestration.
 */
final readonly class ActiveOzonPerformanceConnectionsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{id: string, company_id: string, client_id: null|string}>
     */
    public function execute(?string $companyId = null): array
    {
        $sql = <<<'SQL'
            SELECT mc.id, mc.company_id, mc.client_id
            FROM marketplace_connections mc
            WHERE mc.marketplace = :marketplace
              AND mc.connection_type = :connection_type
              AND mc.is_active = true
        SQL;

        $params = [
            'marketplace' => MarketplaceType::OZON->value,
            'connection_type' => MarketplaceConnectionType::PERFORMANCE->value,
        ];

        if (null !== $companyId) {
            $sql .= ' AND mc.company_id = :company_id';
            $params['company_id'] = $companyId;
        }

        $sql .= ' ORDER BY mc.created_at ASC, mc.id ASC';

        return $this->connection->fetchAllAssociative($sql, $params);
    }
}
