<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Application\DTO;

use App\MarketplaceOperations\Enum\OperationKind;

/** Операция канона для чтения (витрина). Сумма в копейках, знак как в каноне. */
final readonly class OperationRecord
{
    public function __construct(
        public ?string $listingId,
        public OperationKind $kind,
        public string $sourceType,
        public int $amountMinor,
        public string $currency,
        public ?int $quantity,
    ) {
    }
}
