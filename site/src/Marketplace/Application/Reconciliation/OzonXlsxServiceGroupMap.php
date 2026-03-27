<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Reconciliation;

/**
 * Маппинг наших категорий затрат к группам xlsx «Детализации начислений» Ozon.
 *
 * Используется ТОЛЬКО для сверки xlsx vs API данные.
 * НЕ влияет на маппинг к ОПиУ категориям (это OzonServiceCategoryMap).
 *
 * Источник групп: xlsx-отчёт «Детализация начислений» из ЛК Ozon,
 * колонка «Группа услуг» (column index 2).
 */
final class OzonXlsxServiceGroupMap
{
    /**
     * @return array<string, string> category_code → serviceGroup (название группы в xlsx)
     */
    public static function getCategoryToServiceGroup(): array
    {
        return [
            // Вознаграждение Ozon
            'ozon_sale_commission'       => 'Вознаграждение Ozon',

            // Продвижение и реклама
            'ozon_cpc'                   => 'Продвижение и реклама',
            'ozon_premium_promotion'     => 'Продвижение и реклама',
            'ozon_premium_cashback'      => 'Продвижение и реклама',
            'ozon_reviews'               => 'Продвижение и реклама',
            'ozon_seller_bonus'          => 'Продвижение и реклама',
            'ozon_marketing_action'      => 'Продвижение и реклама',

            // Услуги доставки
            'ozon_logistic_direct'       => 'Услуги доставки',
            'ozon_logistic_return'       => 'Услуги доставки',
            'ozon_dropoff_pvz'           => 'Услуги доставки',

            // Услуги FBO
            'ozon_crossdocking'          => 'Услуги FBO',
            'ozon_supply_shortage'       => 'Услуги FBO',
            'ozon_return_from_stock'     => 'Услуги FBO',
            'ozon_supply_additional'     => 'Услуги FBO',
            'ozon_storage'               => 'Услуги FBO',
            'ozon_disposal'              => 'Услуги FBO',
            'ozon_fulfillment'           => 'Услуги FBO',

            // Услуги партнёров
            'ozon_logistic_last_mile'    => 'Услуги партнёров',
            'ozon_logistic_pickup'       => 'Услуги партнёров',
            'ozon_return_pvz'            => 'Услуги партнёров',
            'ozon_storage_partner'       => 'Услуги партнёров',
            'ozon_acquiring'             => 'Услуги партнёров',

            // Другие услуги и штрафы
            'ozon_penalty_undeliverable' => 'Другие услуги и штрафы',
        ];
    }
}
