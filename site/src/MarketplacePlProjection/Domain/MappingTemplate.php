<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Domain;

use App\MarketplaceOperations\Enum\OperationKind;

/**
 * Шаблон платформы «вид канона → статья стандартного дерева ОПиУ» (`DATA.md` §9.3). Источник —
 * легаси `default_cost_mapping.yaml` / `default_sale_mapping.yaml` по таблице
 * `stage-1-category-levels.md`. Правка шаблона — поднять `VERSION`: синхронизация обновит
 * `preset`-правила компаний, `custom` не тронет.
 */
final class MappingTemplate
{
    public const VERSION = 2;

    /** Вид → код статьи; `null` — справочный вид, в ОПиУ не идёт. */
    private const BY_KIND = [
        'sale' => 'REV_NOT_SPP',
        'return' => 'REV_RETURNS',
        'spp' => null,
        'coinvestment' => null,
        'commission' => 'COGS_MP_COMMISSION',
        'commission_brand' => 'COGS_MP_COMMISSION',
        'logistics_to' => 'COGS_DELIVERY',
        'logistics_to_last_mile' => 'COGS_DELIVERY',
        'logistics_to_dropoff' => 'COGS_DELIVERY',
        'logistics_to_handover' => 'COGS_DELIVERY',
        'logistics_back' => 'COGS_RETURNS_DELIVERY',
        'logistics_back_pvz' => 'COGS_RETURNS_DELIVERY',
        'acquiring' => 'COGS_ACQUIRING',
        'packaging_labor' => 'OPEX_WH_STORAGE',
        'packaging_materials' => 'OPEX_WH_STORAGE',
        'packaging_warehouse' => 'OPEX_WH_STORAGE',
        'storage' => 'OPEX_WH_STORAGE',
        'stock_removal' => 'COGS_RETURNS_DELIVERY',
        'disposal' => 'OPEX_WH_MP_DEDUCTIONS',
        'ads_cpc' => 'PROMO_INTERNAL',
        'ads_pay_per_order' => 'PROMO_INTERNAL',
        'promo_stars' => 'PROMO_INTERNAL',
        'promo_premium' => 'PROMO_INTERNAL',
        'promo_seller_bonus' => 'PROMO_INTERNAL',
        'promo_reviews' => 'PROMO_INTERNAL',
        'promo_chat_points' => 'PROMO_INTERNAL',
        'subscription' => 'PROMO_INTERNAL',
        'fine' => 'OPEX_WH_MP_DEDUCTIONS',
        'service_correction' => 'OPEX_WH_MP_DEDUCTIONS',
        'compensation' => 'OPEX_WH_COMPENSATION',
        'mp_other_deductions' => 'OPEX_WH_MP_DEDUCTIONS',
        'ads_settlement' => null,
    ];

    /** Исключения: [вид | null, канал, код источника | null, код статьи] — как эти строки вёл легаси. */
    private const EXCEPTIONS = [
        ['fine', 'wildberries', null, 'OPEX_WH_PENALTIES'],
        // Услуги Ozon, которые легаси ведёт не в статью своего вида (сверка с продом 16.11.2026).
        [null, 'ozon', 'ozon:service:12', 'OPEX_WH_RECEIVING'],
        [null, 'ozon', 'ozon:service:18', 'OVERHEAD_ADMIN_BANK'],
        [null, 'ozon', 'ozon:service:68', 'OPEX_WH_MP_DEDUCTIONS'],
        [null, 'ozon', 'ozon:service:76', 'OPEX_WH_MP_DEDUCTIONS'],
        [null, 'ozon', 'ozon:service:77', 'OPEX_WH_RECEIVING'],
        [null, 'ozon', 'ozon:service:118', 'OVERHEAD_PROD_CERT'],
        [null, 'wildberries', 'wb:field:rebillLogisticCost', 'OPEX_WH_STORAGE'],
        [null, 'wildberries', 'wb:field:ppvzReward', 'COGS_RETURNS_DELIVERY'],
        [null, 'wildberries', 'wb:field:paidAcceptance', 'OPEX_WH_RECEIVING'],
        [null, 'wildberries', 'wb:field:cashbackCommissionChange', 'PROMO_GIVEAWAYS'],
        [null, 'wildberries', 'wb:field:cashbackAmount', 'PROMO_GIVEAWAYS'],
        [null, 'wildberries', 'wb:field:cashbackDiscount', 'PROMO_INTERNAL'],
    ];

    /** @return list<TemplateRule> */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::BY_KIND as $kind => $plCode) {
            $rules[] = new TemplateRule(OperationKind::from($kind), null, null, $plCode);
        }
        foreach (self::EXCEPTIONS as [$kind, $channel, $sourceType, $plCode]) {
            $rules[] = new TemplateRule(null === $kind ? null : OperationKind::from($kind), $channel, $sourceType, $plCode);
        }

        return $rules;
    }
}
