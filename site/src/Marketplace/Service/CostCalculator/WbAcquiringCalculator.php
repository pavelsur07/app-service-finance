<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbAcquiringCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        return ($item['doc_type_name'] ?? '') === 'Продажа';
    }

    public function requiresListing(): bool
    {
        return true;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $acquiringFee = (float)($item['acquiring_fee'] ?? 0);

        if (abs($acquiringFee) < 0.01) {
            return [];
        }

        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        return [
            [
                'category_code' => 'acquiring',
                'amount' => (string)abs($acquiringFee),
                'external_id' => $srid . '_acquiring',
                'cost_date' => $saleDate,
                'description' => 'Эквайринг',
                'product' => $listing?->getProduct(),
            ],
        ];
    }
}
