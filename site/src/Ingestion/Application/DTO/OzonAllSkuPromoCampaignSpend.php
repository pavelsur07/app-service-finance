<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Кампания Ozon Performance «оплата за заказ, все товары» (ALL_SKU_PROMO): расход только по кампании
 * за день, без SKU, и список товаров кампании.
 */
final readonly class OzonAllSkuPromoCampaignSpend
{
    /**
     * @param list<string> $skus SKU товаров кампании (Ozon SKU)
     * @param array<string, string> $spendByDay Y-m-d => расход, numeric-string, 2 знака
     */
    public function __construct(
        public string $campaignId,
        public array $skus,
        public array $spendByDay,
    ) {
    }
}
