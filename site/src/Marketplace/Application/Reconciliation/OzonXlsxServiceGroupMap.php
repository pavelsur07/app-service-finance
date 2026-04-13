<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Reconciliation;

/**
 * Маппинг наших категорий затрат к группам xlsx «Детализации начислений» Ozon.
 *
 * Используется ТОЛЬКО для сверки xlsx vs API данные.
 * НЕ влияет на маппинг к ОПиУ категориям (это OzonServiceCategoryMap).
 *
 * Группы верифицированы по реальным xlsx-файлам января и февраля 2026.
 *
 * @see OzonServiceCategoryMap — маппинг для ОПиУ (независимый)
 */
final class OzonXlsxServiceGroupMap
{
    /**
     * @return array<string, string> category_code → serviceGroup (название группы в xlsx)
     */
    public static function getCategoryToServiceGroup(): array
    {
        return [
            // === Вознаграждение Ozon ===
            // Типы: Вознаграждение за продажу, Возврат вознаграждения
            'ozon_sale_commission'       => 'Вознаграждение Ozon',
            'ozon_brand_commission'      => 'Вознаграждение Ozon',

            // === Продвижение и реклама ===
            // Типы: Оплата за клик, Подписка Premium Plus, Бонусы продавца,
            //       Бонусы продавца - рассылка, Баллы за отзывы, Продвижение с оплатой за заказ,
            //       Звёздные товары
            'ozon_cpc'                   => 'Продвижение и реклама',
            'ozon_premium_promotion'     => 'Продвижение и реклама',
            'ozon_premium_cashback'      => 'Продвижение и реклама',
            'ozon_reviews'               => 'Продвижение и реклама',
            'ozon_seller_bonus'          => 'Продвижение и реклама',
            'ozon_marketing_action'      => 'Продвижение и реклама',
            'ozon_stars_membership'      => 'Продвижение и реклама',

            // === Услуги доставки ===
            // Типы: Логистика, Логистика - отмена начисления,
            //       Обратная логистика, Обработка возвратов Ozon,
            //       Обработка отменённых и невостребованных товаров,
            //       Обработка отправления Drop-off (ПВЗ), Обработка отправления Drop-off (СЦ),
            //       Логистика вРЦ, Магистраль, Доставка КГТ, Возвраты после доставки
            'ozon_logistic_direct'       => 'Услуги доставки',
            'ozon_logistic_direct_vdc'   => 'Услуги доставки',
            'ozon_logistic_direct_trans' => 'Услуги доставки',
            'ozon_logistic_delivery'     => 'Услуги доставки',
            'ozon_logistic_kgt'          => 'Услуги доставки',
            'ozon_logistic_return'       => 'Услуги доставки',
            'ozon_logistic_return_trans' => 'Услуги доставки',
            'ozon_logistic_inbound'      => 'Услуги доставки',
            'ozon_logistic_inbound_seller' => 'Услуги доставки',
            'ozon_dropoff_pvz'           => 'Услуги доставки',
            'ozon_dropoff_ff'            => 'Услуги доставки',
            'ozon_dropoff_sc'            => 'Услуги доставки',
            'ozon_dropoff_ppz'           => 'Услуги доставки',
            'ozon_delivery'              => 'Услуги доставки',
            'ozon_return_delivery'       => 'Услуги доставки',
            'ozon_return_partial'        => 'Услуги доставки',
            'ozon_return_not_delivered'  => 'Услуги доставки',
            'ozon_return_after_delivery' => 'Услуги доставки',
            'ozon_return_storage_pvz'    => 'Услуги доставки',
            'ozon_return_storage_wh'     => 'Услуги доставки',

            // === Услуги FBO ===
            // Типы: Кросс-докинг, Бронирование места, Вывоз товара со склада: Доставка до ПВЗ,
            //       Обработка брака, Обработка срока годности, Поштучная приёмка,
            //       Подготовка к вывозу: Брак/Валид, Размещение товаров на складах,
            //       Перемещение товаров между складами, Сборка заказа,
            //       Упаковочные материалы, Упаковка партнёрами, Обработка излишков
            'ozon_crossdocking'          => 'Услуги FBO',
            'ozon_supply_shortage'       => 'Услуги FBO',
            'ozon_return_from_stock'     => 'Услуги FBO',
            'ozon_supply_additional'     => 'Услуги FBO',
            'ozon_supply_surplus'        => 'Услуги FBO',
            'ozon_storage'               => 'Услуги FBO',
            'ozon_logistic_pickup'       => 'Услуги FBO',   // Вывоз товара со склада силами Ozon: Доставка до ПВЗ
            'ozon_package_materials'     => 'Услуги FBO',
            'ozon_package_labor'         => 'Услуги FBO',
            'ozon_warehouse_movement'    => 'Услуги FBO',

            // === Услуги партнёров ===
            // Типы: Доставка до места выдачи, Доставка до места выдачи - отмена начисления,
            //       Обработка возвратов/отмен/невыкупов партнёрами,
            //       Обработка отправления Drop-off партнёрами (АПВЗ),
            //       Временное размещение товара партнёрами, Эквайринг
            'ozon_logistic_last_mile'    => 'Услуги партнёров',
            'ozon_return_pvz'            => 'Услуги партнёров',
            'ozon_storage_partner'       => 'Услуги партнёров',
            'ozon_acquiring'             => 'Услуги партнёров',
            'ozon_fulfillment'           => 'Услуги партнёров',  // Обработка отправления Drop-off партнёрами (АПВЗ)

            // === Другие услуги и штрафы ===
            // Типы: Утилизация товара (все виды), Модерация запрещённого контента,
            //       Дополнительная обработка ОВХ, Обработка операционных ошибок продавца,
            //       Маркировка, Агентская услуга, Финансовые услуги, Корректировки
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

            // === Компенсации и декомпенсации ===
            // Типы: Компенсация, Декомпенсация
            'ozon_compensation'          => 'Компенсации и декомпенсации',
            'ozon_decompensation'        => 'Компенсации и декомпенсации',
        ];
    }
}
