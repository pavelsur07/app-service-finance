<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class PLCategoriesByCodeQuery
{
    public function __construct(private Connection $connection) {}

    /** @param list<string> $codes @return array<string, list<array{id: string, code: string, name: string, type: string, flow: string, is_visible: bool}>> */
    public function fetchIndexed(string $companyId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.code, c.name, c.type, c.flow, c.is_visible FROM pl_categories c WHERE c.company_id = :companyId AND c.code IN (:codes)',
            ['companyId' => $companyId, 'codes' => array_values(array_unique($codes))],
            ['codes' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $result[$code] ??= [];
            $result[$code][] = [
                'id' => (string) $row['id'],
                'code' => $code,
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'flow' => (string) $row['flow'],
                'is_visible' => (bool) $row['is_visible'],
            ];
        }

        return $result;
    }
}
