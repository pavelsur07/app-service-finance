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

    /**
     * Получить список продуктов компании (для select в UI)
     *
     * @return array<int, array{id: string, sku: string, name: string}>
     */
    public function fetchAllForCompany(string $companyId): array
    {
        return $this->connection->createQueryBuilder()
            ->select('p.id', 'p.sku', 'p.name')
            ->from('products', 'p')
            ->where('p.company_id = :company')
            ->orderBy('p.name', 'ASC')
            ->setParameter('company', $companyId)
            ->setMaxResults(500)  // Ограничение для производительности
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Найти продукт по ID (с проверкой принадлежности к компании!)
     */
    public function findByIdAndCompany(string $productId, string $companyId): ?array
    {
        $result = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.sku', 'p.name', 'p.company_id')
            ->from('products', 'p')
            ->where('p.id = :id')
            ->andWhere('p.company_id = :company')  // ← КРИТИЧНО!
            ->setParameter('id', $productId)
            ->setParameter('company', $companyId)
            ->executeQuery()
            ->fetchAssociative();

        return $result ?: null;
    }
}

