<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbAcquiringCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        // Только продажи WB
        return ($item['doc_type_name'] ?? '') === 'Продажа';
    }

    public function calculate(array $item, MarketplaceListing $listing): array
    {
        $acquiringFee = (float)($item['acquiring_fee'] ?? 0);

        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Создаём даже если 0 (для отладки)
        return [
            [
                'category_code' => 'acquiring', // Без префикса wb_
                'amount' => (string)abs($acquiringFee),
                'external_id' => $srid . '_acquiring',
                'cost_date' => $saleDate,
                'description' => 'Эквайринг',
            ],
        ];
    }
}
