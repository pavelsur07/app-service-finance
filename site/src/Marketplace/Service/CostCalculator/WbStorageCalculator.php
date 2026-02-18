<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbStorageCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        return ($item['supplier_oper_name'] ?? '') === 'Хранение';
    }

    public function requiresListing(): bool
    {
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $storageFee = (float)($item['storage_fee'] ?? 0);
        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        return [
            [
                'category_code' => 'storage',
                'amount' => (string)abs($storageFee),
                'external_id' => $srid . '_storage',
                'cost_date' => $saleDate,
                'description' => 'Хранение WB',
                'product' => null, // Нет привязки к товару
            ],
        ];
    }
}
