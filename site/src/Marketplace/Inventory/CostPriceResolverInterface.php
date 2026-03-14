<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory;

/**
 * Контракт получения себестоимости товара для листинга на дату.
 *
 * Себестоимость привязана к листингу — не к продукту.
 * Один листинг Ozon и один листинг WB одного товара могут иметь разные цены.
 *
 * Все потребители зависят только от этого интерфейса.
 * Этап 1: реализует InventoryCostPriceResolver (таблица marketplace_inventory_cost_prices).
 * Этап 2: партионный резолвер — потребители не меняются.
 *
 * Возвращает всегда string decimal. При отсутствии записи — '0.00'.
 */
interface CostPriceResolverInterface
{
    /**
     * @param string             $companyId UUID компании
     * @param string             $listingId UUID листинга (MarketplaceListing)
     * @param \DateTimeImmutable $date      дата на которую нужна себестоимость
     *
     * @return string decimal ('850.00'), при отсутствии '0.00'
     */
    public function resolve(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): string;
}
