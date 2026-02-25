<?php

declare(strict_types=1);

namespace App\Catalog\Facade;

use App\Catalog\DTO\PurchasePriceAtDto;
use App\Catalog\Infrastructure\Query\ProductPurchasePriceQuery;

final readonly class ProductPurchasePriceFacade
{
    public function __construct(private ProductPurchasePriceQuery $productPurchasePriceQuery)
    {
    }

    public function getPurchasePriceAt(string $companyId, string $productId, \DateTimeImmutable $at): ?PurchasePriceAtDto
    {
        // Всегда читаем цену в границах компании, companyId передаётся явно.
        $row = $this->productPurchasePriceQuery->findPriceAtDate($companyId, $productId, $at);
        if (null === $row) {
            return null;
        }

        return new PurchasePriceAtDto(
            effectiveFrom: (string) $row['effectiveFrom'],
            effectiveTo: isset($row['effectiveTo']) ? (null !== $row['effectiveTo'] ? (string) $row['effectiveTo'] : null) : null,
            amount: (int) $row['priceAmount'],
            currency: (string) $row['priceCurrency'],
            note: isset($row['note']) ? (null !== $row['note'] ? (string) $row['note'] : null) : null,
        );
    }
}
