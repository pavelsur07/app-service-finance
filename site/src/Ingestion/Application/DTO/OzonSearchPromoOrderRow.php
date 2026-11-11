<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Строка отчёта Ozon Performance по заказам «Оплаты за заказ»: день заказа (Y-m-d), SKU заказанного
 * и продвигаемого товара (пустая строка — нет в отчёте), расход — положительная decimal-строка.
 */
final readonly class OzonSearchPromoOrderRow
{
    /**
     * @param numeric-string $expense
     */
    public function __construct(
        public string $day,
        public string $sku,
        public string $promotedSku,
        public string $expense,
    ) {
    }
}
