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

    /**
     * Получить закупочную цену на дату (nearest neighbor):
     * - если дата раньше всех записей — вернёт первую известную цену
     * - если дата позже — вернёт последнюю до даты
     * - если записей нет — вернёт null
     */
    public function getPurchasePriceAt(string $companyId, string $productId, \DateTimeImmutable $at): ?PurchasePriceAtDto
    {
        $row = $this->productPurchasePriceQuery->findPriceAtDate($companyId, $productId, $at);
        if (null === $row) {
            return null;
        }

        return new PurchasePriceAtDto(
            effectiveFrom: (string) $row['effectiveFrom'],
            effectiveTo:   isset($row['effectiveTo']) ? ($row['effectiveTo'] !== null ? (string) $row['effectiveTo'] : null) : null,
            amount:        (string) $row['priceAmount'],
            currency:      (string) $row['priceCurrency'],
            note:          isset($row['note']) ? ($row['note'] !== null ? (string) $row['note'] : null) : null,
        );
    }
}
