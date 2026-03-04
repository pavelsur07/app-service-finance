<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Определяет, из какого поля MarketplaceSale / MarketplaceReturn
 * берётся сумма для строки ОПиУ.
 *
 * Одна продажа может создать несколько строк ОПиУ с разными AmountSource:
 *   - SALE_GROSS      → pricePerUnit × quantity  (выручка без СПП)
 *   - SALE_REVENUE    → totalRevenue             (выручка с СПП)
 *   - SALE_COST_PRICE → costPrice × quantity     (себестоимость)
 *
 * Для возвратов аналогично:
 *   - RETURN_REFUND     → refundAmount                   (сумма возврата)
 *   - RETURN_GROSS      → sale.pricePerUnit × quantity   (через LEFT JOIN)
 *   - RETURN_COST_PRICE → sale.costPrice × quantity      (через LEFT JOIN)
 */
enum AmountSource: string
{
    // === Sale sources ===
    case SALE_GROSS = 'sale_gross';
    case SALE_REVENUE = 'sale_revenue';
    case SALE_COST_PRICE = 'sale_cost_price';

    // === Return sources ===
    case RETURN_REFUND = 'return_refund';
    case RETURN_GROSS = 'return_gross';
    case RETURN_COST_PRICE = 'return_cost_price';

    /**
     * Тип операции: sale или return.
     * Используется для фильтрации маппингов при агрегации.
     */
    public function getOperationType(): string
    {
        return match ($this) {
            self::SALE_GROSS,
            self::SALE_REVENUE,
            self::SALE_COST_PRICE => 'sale',

            self::RETURN_REFUND,
            self::RETURN_GROSS,
            self::RETURN_COST_PRICE => 'return',
        };
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::SALE_GROSS => 'Выкупы без СПП (цена × кол-во)',
            self::SALE_REVENUE => 'Выручка с СПП (totalRevenue)',
            self::SALE_COST_PRICE => 'Себестоимость продаж (costPrice × кол-во)',
            self::RETURN_REFUND => 'Сумма возврата (refundAmount)',
            self::RETURN_GROSS => 'Возврат без СПП (цена × кол-во)',
            self::RETURN_COST_PRICE => 'Себестоимость возвратов (costPrice × кол-во)',
        };
    }

    /**
     * SQL-выражение для расчёта суммы в DBAL-запросе.
     *
     * @return string SQL CASE branch
     */
    public function getSqlExpression(): string
    {
        return match ($this) {
            self::SALE_GROSS => 's.price_per_unit * s.quantity',
            self::SALE_REVENUE => 's.total_revenue',
            self::SALE_COST_PRICE => 's.cost_price * s.quantity',
            self::RETURN_REFUND => 'r.refund_amount',
            self::RETURN_GROSS => 'ms.price_per_unit * r.quantity',
            self::RETURN_COST_PRICE => 'ms.cost_price * r.quantity',
        };
    }
}
