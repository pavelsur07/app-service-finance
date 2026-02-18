<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

class WbWarehouseLogisticsCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        return ($item['supplier_oper_name'] ?? '') === 'Возмещение издержек по перевозке/по складским операциям с товаром';
    }

    public function requiresListing(): bool
    {
        // Не блокируем — listing опционален, решаем внутри calculate()
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $rebillLogisticCost = (float)($item['rebill_logistic_cost'] ?? 0);
        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Привязываем к товару только если listing найден (nm_id + sa_name были заполнены)
        $product = $listing?->getProduct();

        return [
            [
                'category_code' => 'warehouse_logistics',
                'amount' => (string)abs($rebillLogisticCost),
                'external_id' => $srid . '_warehouse_logistics',
                'cost_date' => $saleDate,
                'description' => 'Логистика складские операции',
                'product' => $product, // null если нет привязки
            ],
        ];
    }
}
