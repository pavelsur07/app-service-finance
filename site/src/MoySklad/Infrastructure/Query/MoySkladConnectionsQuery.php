<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class MoySkladConnectionsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{id:string,name:string,baseUrl:string,isActive:bool,lastSyncAt:?string,updatedAt:string}>
     */
    public function allByCompanyId(string $companyId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'name', 'base_url AS "baseUrl"', 'is_active AS "isActive"', 'last_sync_at AS "lastSyncAt"', 'updated_at AS "updatedAt"')
            ->from('moysklad_connections')
            ->where('company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('updated_at', 'DESC')
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'baseUrl' => (string) $row['baseUrl'],
            'isActive' => (bool) $row['isActive'],
            'lastSyncAt' => $row['lastSyncAt'] !== null ? (string) $row['lastSyncAt'] : null,
            'updatedAt' => (string) $row['updatedAt'],
        ], $rows);
    }
}
