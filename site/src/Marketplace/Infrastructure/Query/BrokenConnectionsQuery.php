<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL Query: подключения компании, чей ключ маркетплейс перестал принимать.
 *
 * Читатели — баннер на дашборде и страница подключений, оба на горячем пути
 * отрисовки, поэтому Fast Read без EntityManager. Своего индекса выборке не
 * нужно: фильтр по `company_id` покрыт `idx_connection_company`, а подключений
 * у компании единицы.
 */
final class BrokenConnectionsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{id: string, marketplace: string, connection_type: string, auth_failed_at: ?string}>
     */
    public function execute(string $companyId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT mc.id, mc.marketplace, mc.connection_type, mc.auth_failed_at
             FROM marketplace_connections mc
             WHERE mc.company_id = :companyId
               AND mc.auth_status = 'failed'
             ORDER BY mc.auth_failed_at, mc.id",
            ['companyId' => $companyId],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'marketplace' => (string) $row['marketplace'],
                'connection_type' => (string) $row['connection_type'],
                'auth_failed_at' => null === $row['auth_failed_at'] ? null : (string) $row['auth_failed_at'],
            ],
            $rows,
        );
    }
}
