<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL Query: активные SELLER-подключения всех маркетплейсов.
 *
 * Используется cron-командами, которые должны пройти по всем парам
 * (companyId, marketplace) — например, ежедневная пересборка предварительного
 * ОПиУ. Возвращает массивы (Fast Read), без EntityManager.
 */
final class ActiveSellerConnectionsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param string|null $companyId ограничить одной компанией
     *
     * @return array<int, array{id: string, company_id: string, marketplace: string}>
     */
    public function execute(?string $companyId = null): array
    {
        // Фильтр по компании выполняет БД, а не вызывающий: отбор после
        // выборки означал бы читать реестр подключений всех компаний ради
        // одной.
        $sql = 'SELECT mc.id, mc.company_id, mc.marketplace
             FROM marketplace_connections mc
             WHERE mc.is_active = true
               AND mc.connection_type = :type';
        $params = ['type' => 'seller'];

        if (null !== $companyId) {
            $sql .= ' AND mc.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        return $this->connection->fetchAllAssociative(
            $sql.' ORDER BY mc.company_id, mc.marketplace',
            $params,
        );
    }
}
