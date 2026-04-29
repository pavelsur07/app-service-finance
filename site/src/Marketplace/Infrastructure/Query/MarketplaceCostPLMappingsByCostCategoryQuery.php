<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class MarketplaceCostPLMappingsByCostCategoryQuery
{
    public function __construct(private Connection $connection) {}

    /** @param list<string> $costCategoryIds @return array<string, array{id: string, cost_category_id: string, pl_category_id: ?string, include_in_pl: bool, is_negative: bool, sort_order: ?int}> */
    public function fetchIndexedByCostCategoryId(string $companyId, array $costCategoryIds): array
    {
        if ($costCategoryIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, cost_category_id, pl_category_id, include_in_pl, is_negative, sort_order FROM marketplace_cost_pl_mappings WHERE company_id = :companyId AND cost_category_id IN (:ids)',
            ['companyId' => $companyId, 'ids' => array_values(array_unique($costCategoryIds))],
            ['ids' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['cost_category_id']] = [
                'id' => (string) $row['id'],
                'cost_category_id' => (string) $row['cost_category_id'],
                'pl_category_id' => $row['pl_category_id'] !== null ? (string) $row['pl_category_id'] : null,
                'include_in_pl' => (bool) $row['include_in_pl'],
                'is_negative' => (bool) $row['is_negative'],
                'sort_order' => $row['sort_order'] !== null ? (int) $row['sort_order'] : null,
            ];
        }

        return $result;
    }
}
