<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbPvzProcessingCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        return ($item['supplier_oper_name'] ?? '') === 'Возмещение за выдачу и возврат товаров на ПВЗ';
    }

    public function requiresListing(): bool
    {
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $ppvzReward = (float)($item['ppvz_reward'] ?? 0);

        if (abs($ppvzReward) < 0.01) {
            return [];
        }

        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        return [
            [
                'category_code' => 'pvz_processing',
                'amount' => (string)abs($ppvzReward),
                'external_id' => $srid . '_pvz_processing',
                'cost_date' => $saleDate,
                'description' => 'Логистика обработка на ПВЗ',
            ],
        ];
    }
}
