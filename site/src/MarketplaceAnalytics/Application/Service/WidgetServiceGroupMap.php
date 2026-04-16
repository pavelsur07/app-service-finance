<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application\Service;

/**
 * Маппинг категорий затрат Ozon к widgetGroup для витрины аналитики.
 *
 * Используется в WidgetSummaryQuery для агрегации затрат по 5 группам
 * виджета на дашборде MarketplaceAnalytics.
 *
 * Отличия от OzonXlsxServiceGroupMap:
 *  - "Услуги доставки" + "Услуги FBO" объединены в "Услуги доставки и FBO"
 *  - "Другие услуги и штрафы" + "Компенсации и декомпенсации" объединены
 *    в "Другие услуги и штрафы"
 *
 * Fallback для неизвестных кодов: 'Другие услуги и штрафы'.
 */
final class WidgetServiceGroupMap
{
    /**
     * @return array<string, string> category_code → widgetGroup
     */
    public static function getCategoryToWidgetGroup(): array
    {
        return [
            // === Вознаграждение ===
            'ozon_sale_commission'       => 'Вознаграждение',
            'ozon_brand_commission'      => 'Вознаграждение',

            // === Услуги доставки и FBO ===
            // Объединяем "Услуги доставки" + "Услуги FBO" из OzonXlsxServiceGroupMap
            // Доставка (20 кодов):
            'ozon_logistic_direct'       => 'Услуги доставки и FBO',
            'ozon_logistic_direct_vdc'   => 'Услуги доставки и FBO',
            'ozon_logistic_direct_trans' => 'Услуги доставки и FBO',
            'ozon_logistic_delivery'     => 'Услуги доставки и FBO',
            'ozon_logistic_kgt'          => 'Услуги доставки и FBO',
            'ozon_logistic_return'       => 'Услуги доставки и FBO',
            'ozon_logistic_return_trans' => 'Услуги доставки и FBO',
            'ozon_logistic_inbound'      => 'Услуги доставки и FBO',
            'ozon_logistic_inbound_seller' => 'Услуги доставки и FBO',
            'ozon_dropoff_pvz'           => 'Услуги доставки и FBO',
            'ozon_dropoff_ff'            => 'Услуги доставки и FBO',
            'ozon_dropoff_sc'            => 'Услуги доставки и FBO',
            'ozon_dropoff_ppz'           => 'Услуги доставки и FBO',
            'ozon_delivery'              => 'Услуги доставки и FBO',
            'ozon_return_delivery'       => 'Услуги доставки и FBO',
            'ozon_return_partial'        => 'Услуги доставки и FBO',
            'ozon_return_not_delivered'  => 'Услуги доставки и FBO',
            'ozon_return_after_delivery' => 'Услуги доставки и FBO',
            'ozon_return_storage_pvz'    => 'Услуги доставки и FBO',
            'ozon_return_storage_wh'     => 'Услуги доставки и FBO',
            // FBO (8 кодов):
            'ozon_crossdocking'          => 'Услуги доставки и FBO',
            'ozon_supply_shortage'       => 'Услуги доставки и FBO',
            'ozon_return_from_stock'     => 'Услуги доставки и FBO',
            'ozon_supply_additional'     => 'Услуги доставки и FBO',
            'ozon_supply_surplus'        => 'Услуги доставки и FBO',
            'ozon_storage'               => 'Услуги доставки и FBO',
            'ozon_logistic_pickup'       => 'Услуги доставки и FBO',
            'ozon_warehouse_movement'    => 'Услуги доставки и FBO',

            // === Услуги партнёров ===
            'ozon_logistic_last_mile'    => 'Услуги партнёров',
            'ozon_return_pvz'            => 'Услуги партнёров',
            'ozon_storage_partner'       => 'Услуги партнёров',
            'ozon_acquiring'             => 'Услуги партнёров',
            'ozon_fulfillment'           => 'Услуги партнёров',
            'ozon_package_labor'         => 'Услуги партнёров',
            'ozon_dropoff_apvz'          => 'Услуги партнёров',

            // === Продвижение и реклама ===
            'ozon_cpc'                   => 'Продвижение и реклама',
            'ozon_premium_promotion'     => 'Продвижение и реклама',
            'ozon_premium_cashback'      => 'Продвижение и реклама',
            'ozon_reviews'               => 'Продвижение и реклама',
            'ozon_seller_bonus'          => 'Продвижение и реклама',
            'ozon_marketing_action'      => 'Продвижение и реклама',
            'ozon_stars_membership'      => 'Продвижение и реклама',

            // === Другие услуги и штрафы ===
            // Объединяем "Другие услуги и штрафы" + "Компенсации и декомпенсации"
            'ozon_package_materials'     => 'Другие услуги и штрафы',
            'ozon_disposal'              => 'Другие услуги и штрафы',
            'ozon_ovh_processing'        => 'Другие услуги и штрафы',
            'ozon_penalty_undeliverable' => 'Другие услуги и штрафы',
            'ozon_marking'               => 'Другие услуги и штрафы',
            'ozon_agency_fee'            => 'Другие услуги и штрафы',
            'ozon_early_payment'         => 'Другие услуги и штрафы',
            'ozon_flexible_payment'      => 'Другие услуги и штрафы',
            'ozon_installment'           => 'Другие услуги и штрафы',
            'ozon_premium_correction'    => 'Другие услуги и штрафы',
            'ozon_service_correction'    => 'Другие услуги и штрафы',
            'ozon_other_service'         => 'Другие услуги и штрафы',
            'ozon_compensation'          => 'Другие услуги и штрафы',
            'ozon_decompensation'        => 'Другие услуги и штрафы',
        ];
    }
}
