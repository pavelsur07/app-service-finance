<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Domain\ValueObject\ListingKey;

/**
 * Неизменяемый DTO для передачи нормализованных данных листинга в ingestion pipeline.
 * DTO не зависит от конкретного маркетплейса и не содержит бизнес-логики.
 */
final readonly class ListingSeedDTO
{
    /**
     * @param ListingKey   $key             Уникальный ключ листинга
     * @param string|null  $supplierSku     SKU поставщика
     * @param string|null  $name            Наименование листинга
     * @param string|null  $price           Базовая цена
     * @param string|null  $discountPrice   Цена со скидкой
     * @param bool         $isActive        Признак активности листинга
     * @param array<mixed>|null $marketplaceData Нормализованные дополнительные данные
     */
    public function __construct(
        public ListingKey $key,
        public ?string $supplierSku,
        public ?string $name,
        public ?string $price,
        public ?string $discountPrice,
        public bool $isActive,
        public ?array $marketplaceData,
    ) {
    }
}
