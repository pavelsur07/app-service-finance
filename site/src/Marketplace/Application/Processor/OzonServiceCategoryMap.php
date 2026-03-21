<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use Psr\Log\LoggerInterface;

/**
 * Единый словарь маппинга service name → category code для Ozon.
 *
 * Используется в OzonSalesRawProcessor и OzonCostsRawProcessor.
 * Изменять маппинг только здесь — оба процессора подхватят автоматически.
 *
 * Группировка для ОПиУ происходит ТОЛЬКО на уровне маппинга PLCategory.
 * Null = нулевой маркер (price всегда 0), пропустить без создания записи.
 */
final class OzonServiceCategoryMap
{
    /**
     * @var array<string, string|null>
     */
    private const MAP = [
        // === ЛОГИСТИКА ПРЯМАЯ ===
        'MarketplaceServiceItemDirectFlowLogistic'               => 'ozon_logistic_direct',
        'MarketplaceServiceItemDirectFlowLogisticVDC'            => 'ozon_logistic_direct_vdc',
        'MarketplaceServiceItemDirectFlowTrans'                  => 'ozon_logistic_direct_trans',
        'MarketplaceDeliveryCostItem'                            => 'ozon_logistic_delivery',
        'MarketplaceServiceItemDelivToCustomer'                  => 'ozon_logistic_last_mile',
        'MarketplaceServiceItemRedistributionLastMileCourier'    => 'ozon_logistic_last_mile',
        'MarketplaceServiceItemDeliveryKGT'                      => 'ozon_logistic_kgt',

        // === ЛОГИСТИКА ОБРАТНАЯ ===
        'MarketplaceServiceItemReturnFlowLogistic'               => 'ozon_logistic_return',
        'MarketplaceServiceItemReturnFlowTrans'                  => 'ozon_logistic_return_trans',

        // === ЛОГИСТИКА ПОСТАВКИ НА СКЛАД ===
        'ItemAdvertisementForSupplierLogistic'                   => 'ozon_logistic_inbound',
        'ItemAdvertisementForSupplierLogisticSeller'             => 'ozon_logistic_inbound_seller',
        'MarketplaceServiceItemPickup'                           => 'ozon_logistic_pickup',

        // === ОБРАБОТКА ОТПРАВЛЕНИЙ ===
        'MarketplaceServiceItemFulfillment'                      => 'ozon_fulfillment',
        'MarketplaceServiceItemDropoffFF'                        => 'ozon_dropoff_ff',
        'MarketplaceServiceItemDropoffPVZ'                       => 'ozon_dropoff_pvz',
        'MarketplaceServiceItemDropoffSC'                        => 'ozon_dropoff_sc',
        'MarketplaceServiceItemDropoffPPZ'                       => 'ozon_dropoff_ppz',

        // === ОБРАБОТКА ВОЗВРАТОВ ===
        'MarketplaceServiceItemRedistributionReturnsPVZ'         => 'ozon_return_pvz',
        'MarketplaceServiceItemReturnPartGoodsCustomer'          => 'ozon_return_partial',
        'MarketplaceNotDeliveredCostItem'                        => 'ozon_return_not_delivered',
        'MarketplaceReturnAfterDeliveryCostItem'                 => 'ozon_return_after_delivery',
        'MarketplaceReturnStorageServiceAtThePickupPointFbsItem' => 'ozon_return_storage_pvz',
        'MarketplaceReturnStorageServiceInTheWarehouseFbsItem'   => 'ozon_return_storage_wh',

        // === НУЛЕВЫЕ МАРКЕРЫ (пропускать, price = 0) ===
        'MarketplaceServiceItemReturnNotDelivToCustomer'         => null,
        'MarketplaceServiceItemReturnAfterDelivToCustomer'       => null,

        // === УПАКОВКА ===
        'MarketplaceServiceItemPackageMaterialsProvision'        => 'ozon_package_materials',
        'MarketplaceServiceItemPackageRedistribution'            => 'ozon_package_labor',

        // === ХРАНЕНИЕ ===
        'OperationMarketplaceServiceStorage'                     => 'ozon_storage',

        // === КРОСС-ДОКИНГ / ПОСТАВКА НА FBO ===
        'MarketplaceServiceItemCrossdocking'                     => 'ozon_crossdocking',
        'OperationMarketplaceSupplyAdditional'                   => 'ozon_supply_additional',
        'OperationMarketplaceServiceSupplyInboundCargoShortage'  => 'ozon_supply_shortage',
        'OperationMarketplaceServiceSupplyInboundCargoSurplus'   => 'ozon_supply_surplus',

        // === ЭКВАЙРИНГ ===
        'MarketplaceRedistributionOfAcquiringOperation'          => 'ozon_acquiring',

        // === РЕКЛАМА ===
        'OperationMarketplaceCostPerClick'                       => 'ozon_cpc',
        'MarketplaceMarketingActionCostItem'                     => 'ozon_marketing_action',
        'MarketplaceSaleReviewsItem'                             => 'ozon_reviews',

        // === ПРОДВИЖЕНИЕ / PREMIUM ===
        'MarketplaceServicePremiumPromotion'                     => 'ozon_premium_promotion',
        'MarketplaceServicePremiumCashbackIndividualPoints'      => 'ozon_premium_cashback',
        'MarketplaceServiceItemElectronicServicesPremiumCashbackIndividualPoints' => 'ozon_premium_cashback',
        'ItemAgentServiceStarsMembership'                        => 'ozon_stars_membership',

        // === ФИНАНСОВЫЕ УСЛУГИ ===
        'OperationMarketplaceServiceEarlyPaymentAccrual'         => 'ozon_early_payment',
        'MarketplaceServiceItemFlexiblePaymentSchedule'          => 'ozon_flexible_payment',
        'MarketplaceServiceItemInstallment'                      => 'ozon_installment',

        // === ШТРАФЫ / УДЕРЖАНИЯ ===
        'OperationMarketplaceWithHoldingForUndeliverableGoods'   => 'ozon_penalty_undeliverable',

        // === ПРОЧЕЕ ===
        'MarketplaceServiceItemMarkingItems'                     => 'ozon_marking',
        'MarketplaceServiceItemReturnFromStock'                  => 'ozon_return_from_stock',
        'MarketplaceServiceSellerReturnsCargoAssortment'         => 'ozon_return_from_stock',
        'OperationMarketplaceAgencyFeeAggregator3PLGlobal'       => 'ozon_agency_fee',
        'MarketplaceServiceItemDisposalDetailed'                 => 'ozon_disposal',
        'MarketplaceServiceProductMovementFromWarehouse'         => 'ozon_logistic_pickup',
        'MarketplaceServiceVolumeWeightCharacsProcessing'        => 'ozon_supply_additional',

        // === АНГЛИЙСКИЕ operation_type ДЛЯ ОПЕРАЦИЙ БЕЗ services[] ===
        // Эти operation_type приходят в поле op['operation_type'] когда services[] пустой
        'OperationMarketplaceServicePremiumCashbackBonusAccrual' => 'ozon_premium_cashback',
        'OperationPointsForReviews'                              => 'ozon_reviews',
        'OperationMarketplaceSupplyExpirationDateProcessing'     => 'ozon_supply_additional',
        'OperationPromotionWithCostPerOrder'                     => 'ozon_marketing_action',

        // === РУССКОЯЗЫЧНЫЕ НАЗВАНИЯ (из op['operation_type_name'] для операций без services[]) ===
        'Подписка Premium Plus'                                  => 'ozon_premium_promotion',
        'Бонусы продавца - рассылка'                             => 'ozon_premium_cashback',
        'Баллы за отзывы'                                        => 'ozon_reviews',
        'Перемещение товаров между складами Ozon'                => 'ozon_crossdocking',
        'Обработка сроков годности на FBO'                       => 'ozon_supply_additional',
        'Модерация запрещённого контента'                        => 'ozon_penalty_undeliverable',
        'Обработка операционных ошибок продавца: отгрузка в нерекомендованный слот' => 'ozon_penalty_undeliverable',
        'Обработка брака с приемки'                              => 'ozon_supply_additional',
    ];

    /**
     * Резолвит category code по точному имени service name.
     * При неизвестном имени — логирует warning и возвращает fallback через fuzzy.
     *
     * @return string|null null = нулевой маркер, пропустить запись
     */
    public static function resolve(string $serviceName, LoggerInterface $logger): ?string
    {
        if (array_key_exists($serviceName, self::MAP)) {
            return self::MAP[$serviceName];
        }

        $fallback = self::fuzzy($serviceName);

        $logger->warning('ozon_unknown_service_name', [
            'service_name' => $serviceName,
            'resolved_to'  => $fallback,
        ]);

        return $fallback;
    }

    /**
     * Проверяет является ли service name нулевым маркером (price = 0, пропустить).
     */
    public static function isZeroMarker(string $serviceName): bool
    {
        return array_key_exists($serviceName, self::MAP) && self::MAP[$serviceName] === null;
    }

    public static function getCategoryName(string $categoryCode): string
    {
        return match ($categoryCode) {
            'ozon_sale_commission'       => 'Комиссия Ozon за продажу',
            'ozon_delivery'              => 'Доставка Ozon',
            'ozon_return_delivery'       => 'Обратная доставка Ozon',
            'ozon_logistic_direct'       => 'Логистика к покупателю Ozon',
            'ozon_logistic_direct_vdc'   => 'Логистика к покупателю (вРЦ) Ozon',
            'ozon_logistic_direct_trans' => 'Магистраль к покупателю Ozon',
            'ozon_logistic_delivery'     => 'Доставка до покупателя Ozon',
            'ozon_logistic_last_mile'    => 'Last mile Ozon',
            'ozon_logistic_kgt'          => 'Доставка КГТ Ozon',
            'ozon_logistic_return'       => 'Обратная логистика Ozon',
            'ozon_logistic_return_trans' => 'Обратная магистраль Ozon',
            'ozon_logistic_inbound'      => 'Кросс-докинг (поставка) Ozon',
            'ozon_logistic_inbound_seller' => 'ТЭУ (поставка продавцом) Ozon',
            'ozon_logistic_pickup'       => 'Выезд за товаром (Pick-up) Ozon',
            'ozon_fulfillment'           => 'Сборка заказа Ozon',
            'ozon_dropoff_ff'            => 'Обработка отправления FF Ozon',
            'ozon_dropoff_pvz'           => 'Обработка отправления ПВЗ Ozon',
            'ozon_dropoff_sc'            => 'Обработка отправления СЦ Ozon',
            'ozon_dropoff_ppz'           => 'Обработка отправления ППЗ Ozon',
            'ozon_return_pvz'            => 'Перевыставление возврата ПВЗ Ozon',
            'ozon_return_partial'        => 'Обработка частичного возврата Ozon',
            'ozon_return_not_delivered'  => 'Возврат невостребованного товара Ozon',
            'ozon_return_after_delivery' => 'Возврат после доставки Ozon',
            'ozon_return_storage_pvz'    => 'Краткосрочное хранение возврата ПВЗ Ozon',
            'ozon_return_storage_wh'     => 'Долгосрочное хранение возврата склад Ozon',
            'ozon_package_materials'     => 'Упаковочные материалы Ozon',
            'ozon_package_labor'         => 'Упаковка партнёрами Ozon',
            'ozon_storage'               => 'Хранение на складе Ozon',
            'ozon_crossdocking'          => 'Кросс-докинг Ozon',
            'ozon_supply_additional'     => 'Обработка товара в грузоместе FBO Ozon',
            'ozon_supply_shortage'       => 'Бронирование места (неполный состав) Ozon',
            'ozon_supply_surplus'        => 'Обработка излишков поставки Ozon',
            'ozon_acquiring'             => 'Эквайринг Ozon',
            'ozon_cpc'                   => 'Оплата за клик Ozon',
            'ozon_marketing_action'      => 'Маркетинговые акции Ozon',
            'ozon_reviews'               => 'Приобретение отзывов Ozon',
            'ozon_premium_promotion'     => 'Продвижение Premium Ozon',
            'ozon_premium_cashback'      => 'Бонусы продавца Premium Ozon',
            'ozon_stars_membership'      => 'Звёздные товары Ozon',
            'ozon_early_payment'         => 'Досрочная выплата Ozon',
            'ozon_flexible_payment'      => 'Гибкий график выплат Ozon',
            'ozon_installment'           => 'Продажа в рассрочку Ozon',
            'ozon_penalty_undeliverable' => 'Удержание за недовложение Ozon',
            'ozon_marking'               => 'Обязательная маркировка Ozon',
            'ozon_return_from_stock'     => 'Комплектация для вывоза продавцом Ozon',
            'ozon_agency_fee'            => 'Агентская услуга 3PL Global Ozon',
            'ozon_disposal'              => 'Утилизация товара Ozon',
            default                      => 'Прочие услуги Ozon',
        };
    }

    private static function fuzzy(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        if (str_contains($lower, 'логистик') || str_contains($lower, 'logistic')
            || str_contains($lower, 'магистраль') || str_contains($lower, 'доставк')) {
            return 'ozon_logistic_direct';
        }
        if (str_contains($lower, 'обработк') || str_contains($lower, 'сборк')
            || str_contains($lower, 'fulfillment') || str_contains($lower, 'dropoff')) {
            return 'ozon_fulfillment';
        }
        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени')) {
            return 'ozon_storage';
        }
        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')) {
            return 'ozon_acquiring';
        }
        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'клик')) {
            return 'ozon_cpc';
        }
        if (str_contains($lower, 'упаковк') || str_contains($lower, 'package')) {
            return 'ozon_package_materials';
        }
        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')
            || str_contains($lower, 'удержани')) {
            return 'ozon_penalty_undeliverable';
        }
        if (str_contains($lower, 'кросс') || str_contains($lower, 'поставк')) {
            return 'ozon_crossdocking';
        }

        return 'ozon_other_service';
    }
}
