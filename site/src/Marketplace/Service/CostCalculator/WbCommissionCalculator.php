<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbCommissionCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        // Только продажи WB
        return ($item['doc_type_name'] ?? '') === 'Продажа';
    }

    public function calculate(array $item, MarketplaceListing $listing): array
    {
        $retailPrice = (float)($item['retail_price'] ?? 0);
        $acquiringFee = (float)($item['acquiring_fee'] ?? 0);
        $ppvzForPay = (float)($item['ppvz_for_pay'] ?? 0);

        // Комиссия = retail_price - acquiring_fee - ppvz_for_pay
        $commission = $retailPrice - $acquiringFee - $ppvzForPay;

        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        return [
            [
                'category_code' => 'commission', // Без префикса wb_
                'amount' => (string)abs($commission),
                'external_id' => $srid . '_commission',
                'cost_date' => $saleDate,
                'description' => 'Комиссия маркетплейса',
            ],
        ];
    }
}
