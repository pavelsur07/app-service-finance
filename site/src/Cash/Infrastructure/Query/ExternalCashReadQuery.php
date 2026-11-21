<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webmozart\Assert\Assert;

final readonly class ExternalCashReadQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{id: string, name: string, currency: string}> */
    public function accounts(string $companyId, ?string $search, ?string $afterId, int $fetchLimit): array
    {
        $rows = $this->references(
            'SELECT ref.id, ref.name, ref.currency FROM money_account ref WHERE ref.company_id = :company AND ref.is_active',
            $companyId,
            $search,
            $afterId,
            $fetchLimit,
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'currency' => (string) $row['currency'],
        ], $rows);
    }

    /** @return list<array{id: string, name: string, parentId: ?string}> */
    public function categories(string $companyId, ?string $search, ?string $afterId, int $fetchLimit): array
    {
        $rows = $this->references(
            'SELECT ref.id, ref.name, parent.id AS parent_id FROM cashflow_categories ref
             LEFT JOIN cashflow_categories parent ON parent.id = ref.parent_id AND parent.company_id = ref.company_id
             WHERE ref.company_id = :company',
            $companyId,
            $search,
            $afterId,
            $fetchLimit,
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'parentId' => null === $row['parent_id'] ? null : (string) $row['parent_id'],
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function references(string $sql, string $companyId, ?string $search, ?string $afterId, int $fetchLimit): array
    {
        Assert::uuid($companyId);
        Assert::range($fetchLimit, 1, 101);
        $parameters = ['company' => $companyId, 'limit' => $fetchLimit];

        if (null !== $search) {
            $sql .= " AND ref.name ILIKE :search ESCAPE '!'";
            $parameters['search'] = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        }
        if (null !== $afterId) {
            Assert::uuid($afterId);
            $sql .= ' AND ref.id > :after';
            $parameters['after'] = $afterId;
        }

        return $this->connection->fetchAllAssociative($sql.' ORDER BY ref.id LIMIT :limit', $parameters, ['limit' => ParameterType::INTEGER]);
    }
}
