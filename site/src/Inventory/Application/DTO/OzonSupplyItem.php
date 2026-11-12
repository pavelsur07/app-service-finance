<?php

declare(strict_types=1);

namespace App\Inventory\Application\DTO;

/**
 * Позиция активной заявки на поставку FBO Ozon: SKU, количество и склад назначения поставки.
 */
final readonly class OzonSupplyItem
{
    public function __construct(
        public string $sku,
        public ?string $offerId,
        public string $quantity,
        public string $orderId,
        public string $orderState,
        public ?string $warehouseId,
        public ?string $warehouseName,
    ) {
    }
}
