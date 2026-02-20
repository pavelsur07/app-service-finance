<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbPenaltyCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        return ($item['supplier_oper_name'] ?? '') === 'Штраф';
    }

    public function requiresListing(): bool
    {
        return false; // Не блокируем — listing опционален
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $penalty = (float)($item['penalty'] ?? 0);
        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Привязываем к товару только если listing найден
        $product = $listing?->getProduct();

        return [
            [
                'category_code' => 'penalty',
                'amount' => (string)abs($penalty),
                'external_id' => $srid . '_penalty',
                'cost_date' => $saleDate,
                'description' => 'Штраф WB',
                'product' => $product,
            ],
        ];
    }
}
