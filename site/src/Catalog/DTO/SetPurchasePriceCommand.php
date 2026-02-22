<?php

declare(strict_types=1);

namespace App\Catalog\DTO;

final class SetPurchasePriceCommand
{
    public string $companyId;
    public string $productId;
    public \DateTimeImmutable $effectiveFrom;
    public int $priceAmount;
    public string $currency = 'RUB';
    public ?string $note = null;
    public ?string $userId = null;
}
