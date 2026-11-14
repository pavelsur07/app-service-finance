<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Domain;

/** Строка витрины дня: листинг (пусто — без SKU) × показатель. Сумма в копейках, знак канона. */
final readonly class EconomicsLine
{
    public function __construct(
        public ?string $listingId,
        /** Вид канона (`sale`, `commission`, …) или расчётный показатель (`cost_price`, …). */
        public string $measure,
        public EconomicsLevel $level,
        public int $amountMinor,
        public ?int $quantity = null,
        /** Ключ распределения для разнесённых сумм. */
        public ?string $allocationKey = null,
    ) {
    }
}
