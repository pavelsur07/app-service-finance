<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Writer;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final readonly class DefaultCostMappingWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function createMapping(string $companyId, string $costCategoryId, string $plCategoryId, bool $includeInPl, bool $isNegative): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO marketplace_cost_pl_mappings
                (id, company_id, cost_category_id, pl_category_id, include_in_pl, is_negative, sort_order, created_at, updated_at)
            VALUES
                (:id, :company_id, :cost_category_id, :pl_category_id, :include_in_pl, :is_negative, :sort_order, :created_at, :updated_at)
            ON CONFLICT (company_id, cost_category_id) DO NOTHING
            SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'company_id' => $companyId,
                'cost_category_id' => $costCategoryId,
                'pl_category_id' => $plCategoryId,
                'include_in_pl' => $includeInPl,
                'is_negative' => $isNegative,
                'sort_order' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function fillEmptyMapping(string $companyId, string $mappingId, string $plCategoryId, bool $includeInPl, bool $isNegative): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->executeStatement(
            'UPDATE marketplace_cost_pl_mappings SET pl_category_id = :pl_category_id, include_in_pl = :include_in_pl, is_negative = :is_negative, updated_at = :updated_at WHERE id = :id AND company_id = :company_id AND pl_category_id IS NULL AND include_in_pl = true',
            [
                'pl_category_id' => $plCategoryId,
                'include_in_pl' => $includeInPl,
                'is_negative' => $isNegative,
                'updated_at' => $now,
                'id' => $mappingId,
                'company_id' => $companyId,
            ],
        );
    }
}
