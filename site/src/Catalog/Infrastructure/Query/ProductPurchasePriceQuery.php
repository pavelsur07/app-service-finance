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
SELECT price_amount, price_currency, effective_from, effective_to
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
        ];
    }
}

