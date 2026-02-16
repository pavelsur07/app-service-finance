<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbLogisticsDeliveryCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        // Логистика + доставка
        return ($item['supplier_oper_name'] ?? '') === 'Логистика'
            && (int)($item['delivery_amount'] ?? 0) === 1;
    }

    public function calculate(array $item, MarketplaceListing $listing): array
    {
        $deliveryRub = (float)($item['delivery_rub'] ?? 0);
        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        return [
            [
                'category_code' => 'logistics_delivery',
                'amount' => (string)abs($deliveryRub),
                'external_id' => $srid . '_logistics_delivery',
                'cost_date' => $saleDate,
                'description' => 'Логистика до покупателя',
            ],
        ];
    }
}
