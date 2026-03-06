<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Domain\ValueObject\ListingKey;

/**
 * DTO для передачи нормализованных данных листинга из маркетплейсов.
 * Не содержит бизнес-логики и не зависит от конкретного маркетплейса.
 */
final class ListingSeedDTO
{
    /**
     * @param ListingKey        $key             Уникальный ключ листинга
     * @param string            $marketplaceSku  Артикул маркетплейса (основной идентификатор листинга)
     * @param string|null       $supplierSku     SKU поставщика (вспомогательная информация)
     * @param string|null       $name            Наименование листинга
     * @param string|null       $price           Базовая цена
     * @param string|null       $discountPrice   Цена со скидкой
     * @param bool              $isActive        Признак активности листинга
     * @param array<mixed>|null $marketplaceData Нормализованные дополнительные данные маркетплейса
     */
    public function __construct(
        public readonly ListingKey $key,
        public readonly string $marketplaceSku,
        public readonly ?string $supplierSku,
        public readonly ?string $name,
        public readonly ?string $price,
        public readonly ?string $discountPrice,
        public readonly bool $isActive,
        public readonly ?array $marketplaceData,
    ) {
    }
}
