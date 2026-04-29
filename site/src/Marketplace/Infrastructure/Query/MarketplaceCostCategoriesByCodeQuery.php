<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class MarketplaceCostCategoriesByCodeQuery
{
    public function __construct(private Connection $connection) {}

    /** @param list<string> $codes @return array<string, array{id: string, code: string, name: string}> */
    public function fetchIndexed(string $companyId, MarketplaceType $marketplace, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, code, name FROM marketplace_cost_categories WHERE company_id = :companyId AND marketplace = :marketplace AND code IN (:codes)',
            ['companyId' => $companyId, 'marketplace' => $marketplace->value, 'codes' => array_values(array_unique($codes))],
            ['codes' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['code']] = ['id' => (string) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']];
        }

        return $result;
    }
}
