<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbLogisticsReturnCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        // Логистика + возврат
        return ($item['supplier_oper_name'] ?? '') === 'Логистика'
            && 1 === (int) ($item['return_amount'] ?? 0);
    }

    public function calculate(array $item, MarketplaceListing $listing): array
    {
        $deliveryRub = (float) ($item['delivery_rub'] ?? 0);
        $srid = (string) $item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        return [
            [
                'category_code' => 'logistics_return',
                'amount' => (string) abs($deliveryRub),
                'external_id' => $srid.'_logistics_return',
                'cost_date' => $saleDate,
                'description' => 'Логистика возврат',
            ],
        ];
    }
}
