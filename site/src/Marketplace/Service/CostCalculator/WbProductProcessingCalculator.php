<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;

/**
 * Калькулятор для затрат "Обработка товара"
 */
class WbProductProcessingCalculator implements CostCalculatorInterface
{
    public function supports(array $item): bool
    {
        return ($item['supplier_oper_name'] ?? '') === 'Обработка товара';
    }

    public function requiresListing(): bool
    {
        // Может быть как с товаром, так и без
        return false;
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        // Сумма затраты - приёмка товара
        $amount = (float)($item['acceptance'] ?? 0);

        if (abs($amount) < 0.01) {
            return [];
        }

        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Проверяем есть ли nm_id и ts_name для привязки к товару
        $nmId = (string)($item['nm_id'] ?? '');
        $tsName = trim($item['ts_name'] ?? '');
        $product = null;

        // Если есть И nm_id И ts_name - привязываем к товару
        if ($nmId !== '' && $tsName !== '' && $listing) {
            $product = $listing->getProduct();
        }

        return [
            [
                'category_code' => 'product_processing',
                'category_name' => 'Обработка товара',
                'amount' => (string)abs($amount),
                'external_id' => $srid . '_product_processing',
                'cost_date' => $saleDate,
                'description' => 'Обработка товара',
                'product' => $product, // Привязка к товару (если есть nm_id + ts_name)
            ],
        ];
    }
}
