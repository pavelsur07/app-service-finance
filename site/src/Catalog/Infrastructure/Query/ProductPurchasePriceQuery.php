<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class ProductPurchasePriceQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function findPriceAtDate(string $companyId, string $productId, \DateTimeImmutable $at): ?array
    {
        $sql = <<<'SQL'
SELECT price_amount, price_currency, effective_from, effective_to, note
FROM product_purchase_prices
WHERE company_id = :companyId
  AND product_id = :productId
  AND effective_from <= :at
  AND (effective_to IS NULL OR effective_to >= :at)
ORDER BY effective_from DESC
LIMIT 1
SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'companyId' => $companyId,
            'productId' => $productId,
            'at' => $at->format('Y-m-d'),
        ]);

        if (false === $row) {
            return null;
        }

        return [
            'priceAmount' => (int) $row['price_amount'],
            'priceCurrency' => (string) $row['price_currency'],
            'effectiveFrom' => (string) $row['effective_from'],
            'effectiveTo' => null !== $row['effective_to'] ? (string) $row['effective_to'] : null,
            'note' => null !== $row['note'] ? (string) $row['note'] : null,
        ];
    }

    /**
     * @return list<array{effectiveFrom:string,effectiveTo:?string,priceAmount:int,priceCurrency:string,note:?string}>
     */
    public function fetchHistory(string $companyId, string $productId, int $limit = 100): array
    {
        $sql = <<<'SQL'
SELECT effective_from, effective_to, price_amount, price_currency, note
FROM product_purchase_prices
WHERE company_id = :companyId
  AND product_id = :productId
ORDER BY effective_from DESC, created_at DESC
LIMIT :limit
SQL;

        // Историю читаем через DBAL без ORM-гидратации, с явным scope по компании.
        $rows = $this->connection->fetchAllAssociative($sql, [
            'companyId' => $companyId,
            'productId' => $productId,
            'limit' => max(1, $limit),
        ], [
            'limit' => \PDO::PARAM_INT,
        ]);

        return array_map(static fn (array $row): array => [
            'effectiveFrom' => (string) $row['effective_from'],
            'effectiveTo' => null !== $row['effective_to'] ? (string) $row['effective_to'] : null,
            'priceAmount' => (int) $row['price_amount'],
            'priceCurrency' => (string) $row['price_currency'],
            'note' => null !== $row['note'] ? (string) $row['note'] : null,
        ], $rows);
    }
}
