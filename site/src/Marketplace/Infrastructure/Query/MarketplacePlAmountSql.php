<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;

/**
 * Сумма строки продажи / возврата по источнику суммы правила маппинга — единая формула для
 * проведения в ОПиУ и его предпросмотра. Копия формулы в другом запросе — будущее расхождение
 * предпросмотра с документом.
 *
 * Алиасы: правило — `m` (marketplace_sale_mappings), продажа — `s`, возврат — `r`, продажа
 * возврата — `ms` (LEFT JOIN по r.sale_id).
 */
final class MarketplacePlAmountSql
{
    /**
     * @param MarketplaceType|null $marketplace null — запрос по нескольким маркетплейсам:
     *                                          выручка без СПП ветвится по s.marketplace
     */
    public static function saleAmount(?MarketplaceType $marketplace): string
    {
        $saleGross = null === $marketplace
            ? sprintf(
                "CASE WHEN s.marketplace = '%s' THEN %s ELSE %s END",
                MarketplaceType::OZON->value,
                AmountSource::SALE_GROSS->getSqlExpression(MarketplaceType::OZON),
                AmountSource::SALE_GROSS->getSqlExpression(MarketplaceType::WILDBERRIES),
            )
            : AmountSource::SALE_GROSS->getSqlExpression($marketplace);

        return <<<SQL
            CASE m.amount_source
                WHEN 'sale_gross'      THEN $saleGross
                WHEN 'sale_revenue'    THEN s.total_revenue
                WHEN 'sale_cost_price' THEN COALESCE(s.cost_price, 0) * s.quantity
                ELSE 0
            END
        SQL;
    }

    public static function returnAmount(): string
    {
        return <<<'SQL'
            CASE m.amount_source
                WHEN 'return_refund'     THEN COALESCE(r.refund_amount, 0)
                WHEN 'return_gross'      THEN COALESCE(ms.price_per_unit * r.quantity, r.refund_amount, 0)
                WHEN 'return_cost_price' THEN COALESCE(r.cost_price * r.quantity, 0)
                ELSE 0
            END
        SQL;
    }
}
