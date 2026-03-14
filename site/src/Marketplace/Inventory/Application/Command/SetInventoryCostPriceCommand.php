<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application\Command;

/**
 * Команда установки себестоимости листинга.
 * Только scalar типы — безопасно для Worker/CLI/сериализации.
 */
final class SetInventoryCostPriceCommand
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $listingId,
        public readonly \DateTimeImmutable $effectiveFrom,
        public readonly string $priceAmount,
        public readonly string $currency = 'RUB',
        public readonly ?string $note = null,
    ) {
    }
}
