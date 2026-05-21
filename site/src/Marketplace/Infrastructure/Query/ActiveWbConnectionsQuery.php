<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL Query: получение активных WB-подключений для ночной синхронизации.
 * Возвращает массивы (не Entity) — Fast Read по правилам проекта.
 */
final class ActiveWbConnectionsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @return list<array{id: string, connection_id: string, company_id: string}>
     */
    public function execute(?string $companyId = null, ?string $connectionId = null): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('mc.id', 'mc.id AS connection_id', 'mc.company_id')
            ->from('marketplace_connections', 'mc')
            ->where('mc.marketplace = :marketplace')
            ->andWhere('mc.is_active = true')
            ->andWhere('mc.connection_type = :connectionType')
            ->setParameter('marketplace', 'wildberries')
            ->setParameter('connectionType', 'seller')
            ->orderBy('mc.created_at', 'ASC');

        if (null !== $companyId) {
            $qb->andWhere('mc.company_id = :companyId')->setParameter('companyId', $companyId);
        }

        if (null !== $connectionId) {
            $qb->andWhere('mc.id = :connectionId')->setParameter('connectionId', $connectionId);
        }

        /** @var list<array{id: string, connection_id: string, company_id: string}> $result */
        $result = $qb->executeQuery()->fetchAllAssociative();

        return $result;
    }
}
