<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Определяет из какого поля берётся сумма для строки ОПиУ.
 *
 * Продажи (из marketplace_sales):
 *   SALE_GROSS      → price_per_unit × quantity  (цена продавца × кол-во)
 *   SALE_REVENUE    → total_revenue              (accruals_for_sale из финансовых транзакций)
 *   SALE_COST_PRICE → cost_price × quantity      (себестоимость)
 *
 * Реализация Ozon (из marketplace_ozon_realizations):
 *   SALE_REALIZATION → delivery_commission.price_per_instance × quantity
 *                      Цена покупателя с учётом СПП скидки.
 *                      Агрегированный месячный отчёт реализации (/v2/finance/realization).
 *                      Только для Ozon. Настраивается отдельным маппингом.
 *
 * Возвраты (из marketplace_returns):
 *   RETURN_REFUND     → refund_amount
 *   RETURN_GROSS      → sale.price_per_unit × quantity (через LEFT JOIN)
 *   RETURN_COST_PRICE → sale.cost_price × quantity     (через LEFT JOIN)
 */
enum AmountSource: string
{
    // === Sale sources ===
    case SALE_GROSS        = 'sale_gross';
    case SALE_REVENUE      = 'sale_revenue';
    case SALE_COST_PRICE   = 'sale_cost_price';
    case SALE_REALIZATION  = 'sale_realization';

    // === Return sources ===
    case RETURN_REFUND     = 'return_refund';
    case RETURN_GROSS      = 'return_gross';
    case RETURN_COST_PRICE = 'return_cost_price';

    public function getOperationType(): string
    {
        return match ($this) {
            self::SALE_GROSS,
            self::SALE_REVENUE,
            self::SALE_COST_PRICE,
            self::SALE_REALIZATION  => 'sale',

            self::RETURN_REFUND,
            self::RETURN_GROSS,
            self::RETURN_COST_PRICE => 'return',
        };
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::SALE_GROSS        => 'Выручка без СПП (цена продавца × кол-во)',
            self::SALE_REVENUE      => 'Выручка (accruals_for_sale)',
            self::SALE_COST_PRICE   => 'Себестоимость продаж (costPrice × кол-во)',
            self::SALE_REALIZATION  => 'Реализация Ozon (price_per_instance × кол-во)',
            self::RETURN_REFUND     => 'Сумма возврата (refundAmount)',
            self::RETURN_GROSS      => 'Возврат без СПП (цена × кол-во)',
            self::RETURN_COST_PRICE => 'Себестоимость возвратов (costPrice × кол-во)',
        };
    }

    public function getSqlExpression(): string
    {
        return match ($this) {
            self::SALE_GROSS        => 's.price_per_unit * s.quantity',
            self::SALE_REVENUE      => 's.total_revenue',
            self::SALE_COST_PRICE   => 's.cost_price * s.quantity',
            self::SALE_REALIZATION  => 'r.total_amount', // marketplace_ozon_realizations: price_per_instance × quantity
            self::RETURN_REFUND     => 'r.refund_amount',
            self::RETURN_GROSS      => 'ms.price_per_unit * r.quantity',
            self::RETURN_COST_PRICE => 'ms.cost_price * r.quantity',
        };
    }
}
