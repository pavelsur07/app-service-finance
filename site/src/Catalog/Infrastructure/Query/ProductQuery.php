<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class ProductQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function findOneForCompany(string $companyId, string $productId): ?array
    {
        $sql = <<<'SQL'
SELECT id, sku, name, description, purchase_price, weight_kg, status, created_at, updated_at
FROM products
WHERE company_id = :companyId
  AND id = :productId
LIMIT 1
SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'companyId' => $companyId,
            'productId' => $productId,
        ]);

        if (false === $row) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'description' => null !== $row['description'] ? (string) $row['description'] : null,
            'purchasePrice' => (string) $row['purchase_price'],
            'weightKg' => null !== $row['weight_kg'] ? (string) $row['weight_kg'] : null,
            'status' => (string) $row['status'],
            'createdAt' => new \DateTimeImmutable((string) $row['created_at']),
            'updatedAt' => new \DateTimeImmutable((string) $row['updated_at']),
        ];
    }
}

