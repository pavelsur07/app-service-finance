<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbLoyaltyDiscountCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        return ($item['supplier_oper_name'] ?? '') === 'Компенсация скидки по программе лояльности';
    }

    public function requiresListing(): bool
    {
        return false; // Не блокируем — listing опционален
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $cashbackDiscount = (float)($item['cashback_discount'] ?? 0);
        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Привязываем к товару только если listing найден
        $product = $listing?->getProduct();

        return [
            [
                'category_code' => 'wb_loyalty_discount_compensation',
                'amount' => (string)abs($cashbackDiscount),
                'external_id' => $srid . '_loyalty_discount',
                'cost_date' => $saleDate,
                'description' => 'Компенсация скидки по программе лояльности WB',
                'product' => $product,
            ],
        ];
    }
}
