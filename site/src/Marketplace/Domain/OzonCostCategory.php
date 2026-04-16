<?php

declare(strict_types=1);

namespace App\Marketplace\Domain;

/**
 * Единый справочник категорий затрат Ozon — единственный источник правды.
 *
 * Каждый category_code описан полностью: имя, группа виджета, группа xlsx,
 * список service_name (из services[].name API Ozon) и operation_type
 * (из operation_type / operation_type_name для операций без services[]).
 *
 * Все остальные мапы (OzonServiceCategoryMap, WidgetServiceGroupMap,
 * OzonXlsxServiceGroupMap) читают данные отсюда.
 *
 * При добавлении нового кода Ozon — добавить ТОЛЬКО сюда.
 */
final readonly class OzonCostCategory
{
    /**
     * @param string[] $serviceNames   Имена сервисов из services[].name в API Ozon
     * @param string[] $operationTypes Коды operation_type / operation_type_name для операций без services[]
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $widgetGroup,
        public string $xlsxGroup,
        public array $serviceNames = [],
        public array $operationTypes = [],
    ) {}

    // -------------------------------------------------------------------------
    // Registry
    // -------------------------------------------------------------------------

    /**
     * @return OzonCostCategory[]
     */
    public static function all(): array
    {
        /** @var OzonCostCategory[]|null $cache */
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = [
            // =================================================================
            // Вознаграждение
            // =================================================================
            new self(
                code: 'ozon_sale_commission',
                name: 'Комиссия Ozon за продажу',
                widgetGroup: 'Вознаграждение',
                xlsxGroup: 'Вознаграждение Ozon',
            ),
            new self(
                code: 'ozon_brand_commission',
                name: 'Брендовая комиссия Ozon',
                widgetGroup: 'Вознаграждение',
                xlsxGroup: 'Вознаграждение Ozon',
                serviceNames: ['MarketplaceServiceBrandCommission'],
            ),

            // =================================================================
            // Услуги доставки и FBO → Услуги доставки (xlsx)
            // =================================================================
            new self(
                code: 'ozon_logistic_direct',
                name: 'Логистика к покупателю Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemDirectFlowLogistic'],
            ),
            new self(
                code: 'ozon_logistic_direct_vdc',
                name: 'Логистика к покупателю (вРЦ) Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemDirectFlowLogisticVDC'],
            ),
            new self(
                code: 'ozon_logistic_direct_trans',
                name: 'Магистраль к покупателю Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemDirectFlowTrans'],
            ),
            new self(
                code: 'ozon_logistic_delivery',
                name: 'Доставка до покупателя Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceDeliveryCostItem'],
            ),
            new self(
                code: 'ozon_logistic_kgt',
                name: 'Доставка КГТ Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemDeliveryKGT'],
            ),
            new self(
                code: 'ozon_logistic_return',
                name: 'Обратная логистика Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemReturnFlowLogistic'],
            ),
            new self(
                code: 'ozon_logistic_return_trans',
                name: 'Обратная магистраль Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemReturnFlowTrans'],
            ),
            new self(
                code: 'ozon_logistic_inbound',
                name: 'Кросс-докинг (поставка) Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['ItemAdvertisementForSupplierLogistic'],
            ),
            new self(
                code: 'ozon_logistic_inbound_seller',
                name: 'ТЭУ (поставка продавцом) Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['ItemAdvertisementForSupplierLogisticSeller'],
            ),
            new self(
                code: 'ozon_dropoff_pvz',
                name: 'Обработка отправления ПВЗ Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemDropoffPVZ'],
            ),
            new self(
                code: 'ozon_dropoff_ff',
                name: 'Обработка отправления FF Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemDropoffFF'],
            ),
            new self(
                code: 'ozon_dropoff_sc',
                name: 'Обработка отправления СЦ Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemDropoffSC'],
            ),
            new self(
                code: 'ozon_dropoff_ppz',
                name: 'Обработка отправления ППЗ Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemDropoffPPZ'],
            ),
            new self(
                code: 'ozon_delivery',
                name: 'Доставка Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
            ),
            new self(
                code: 'ozon_return_delivery',
                name: 'Обратная доставка Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                operationTypes: [
                    'OperationReturnGoodsFBSofRMS',
                    'OperationSellerReturnsCargoAssortmentInvalid',
                    'OperationSellerReturnsCargoAssortmentValid',
                ],
            ),
            new self(
                code: 'ozon_return_partial',
                name: 'Обработка частичного возврата Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceServiceItemReturnPartGoodsCustomer'],
            ),
            new self(
                code: 'ozon_return_not_delivered',
                name: 'Возврат невостребованного товара Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceNotDeliveredCostItem'],
            ),
            new self(
                code: 'ozon_return_after_delivery',
                name: 'Возврат после доставки Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceReturnAfterDeliveryCostItem'],
            ),
            new self(
                code: 'ozon_return_storage_pvz',
                name: 'Краткосрочное хранение возврата ПВЗ Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceReturnStorageServiceAtThePickupPointFbsItem'],
            ),
            new self(
                code: 'ozon_return_storage_wh',
                name: 'Долгосрочное хранение возврата склад Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги доставки',
                serviceNames: ['MarketplaceReturnStorageServiceInTheWarehouseFbsItem'],
            ),

            // =================================================================
            // Услуги доставки и FBO → Услуги FBO (xlsx)
            // =================================================================
            new self(
                code: 'ozon_crossdocking',
                name: 'Кросс-докинг Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги FBO',
                serviceNames: ['MarketplaceServiceItemCrossdocking'],
            ),
            new self(
                code: 'ozon_supply_shortage',
                name: 'Бронирование места (неполный состав) Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги FBO',
                serviceNames: ['OperationMarketplaceServiceSupplyInboundCargoShortage'],
            ),
            new self(
                code: 'ozon_return_from_stock',
                name: 'Комплектация для вывоза продавцом Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги FBO',
                serviceNames: [
                    'MarketplaceServiceItemReturnFromStock',
                    'MarketplaceServiceSellerReturnsCargoAssortment',
                ],
            ),
            new self(
                code: 'ozon_supply_additional',
                name: 'Обработка товара в грузоместе FBO Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги FBO',
                serviceNames: ['OperationMarketplaceSupplyAdditional'],
                operationTypes: [
                    'OperationMarketplaceSupplyExpirationDateProcessing',
                    'OperationMarketplaceSupplyDefectProcessing',
                    'OperationMarketplaceServiceProcessingSpoilageSurplus',
                    'Обработка сроков годности на FBO',
                    'Обработка брака с приемки',
                ],
            ),
            new self(
                code: 'ozon_supply_surplus',
                name: 'Обработка излишков поставки Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги FBO',
                serviceNames: ['OperationMarketplaceServiceSupplyInboundCargoSurplus'],
            ),
            new self(
                code: 'ozon_storage',
                name: 'Хранение на складе Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги FBO',
                serviceNames: ['OperationMarketplaceServiceStorage'],
            ),
            new self(
                code: 'ozon_logistic_pickup',
                name: 'Выезд за товаром (Pick-up) Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги FBO',
                serviceNames: [
                    'MarketplaceServiceItemPickup',
                    'MarketplaceServiceProductMovementFromWarehouse',
                ],
            ),
            new self(
                code: 'ozon_warehouse_movement',
                name: 'Перемещение между складами Ozon',
                widgetGroup: 'Услуги доставки и FBO',
                xlsxGroup: 'Услуги FBO',
                operationTypes: [
                    'MarketplaceServiceItemReplenishment',
                    'OperationMarketplaceWarehouseToWarehouseMovement',
                    'Перемещение товаров между складами Ozon',
                ],
            ),

            // =================================================================
            // Услуги партнёров
            // =================================================================
            new self(
                code: 'ozon_logistic_last_mile',
                name: 'Last mile Ozon',
                widgetGroup: 'Услуги партнёров',
                xlsxGroup: 'Услуги партнёров',
                serviceNames: [
                    'MarketplaceServiceItemDelivToCustomer',
                    'MarketplaceServiceItemRedistributionLastMileCourier',
                ],
            ),
            new self(
                code: 'ozon_return_pvz',
                name: 'Перевыставление возврата ПВЗ Ozon',
                widgetGroup: 'Услуги партнёров',
                xlsxGroup: 'Услуги партнёров',
                serviceNames: ['MarketplaceServiceItemRedistributionReturnsPVZ'],
                operationTypes: ['SellerReturnsDeliveryToPickupPoint'],
            ),
            new self(
                code: 'ozon_storage_partner',
                name: 'Временное хранение у партнёров Ozon',
                widgetGroup: 'Услуги партнёров',
                xlsxGroup: 'Услуги партнёров',
                serviceNames: [
                    'MarketplaceServiceItemTemporaryStorageRedistribution',
                    'OperationMarketplaceItemTemporaryStorageRedistribution',
                ],
                operationTypes: ['Временное размещение товара партнерами'],
            ),
            new self(
                code: 'ozon_acquiring',
                name: 'Эквайринг Ozon',
                widgetGroup: 'Услуги партнёров',
                xlsxGroup: 'Услуги партнёров',
                serviceNames: ['MarketplaceRedistributionOfAcquiringOperation'],
            ),
            new self(
                code: 'ozon_fulfillment',
                name: 'Сборка заказа Ozon',
                widgetGroup: 'Услуги партнёров',
                xlsxGroup: 'Услуги партнёров',
                serviceNames: ['MarketplaceServiceItemFulfillment'],
            ),
            new self(
                code: 'ozon_package_labor',
                name: 'Упаковка партнёрами Ozon',
                widgetGroup: 'Услуги партнёров',
                xlsxGroup: 'Услуги FBO',
                serviceNames: ['MarketplaceServiceItemPackageRedistribution'],
            ),
            new self(
                code: 'ozon_dropoff_apvz',
                name: 'Дропофф АПВЗ Ozon',
                widgetGroup: 'Услуги партнёров',
                xlsxGroup: 'Услуги партнёров',
                serviceNames: ['MarketplaceServiceItemRedistributionDropOffApvz'],
            ),

            // =================================================================
            // Продвижение и реклама
            // =================================================================
            new self(
                code: 'ozon_cpc',
                name: 'Оплата за клик Ozon',
                widgetGroup: 'Продвижение и реклама',
                xlsxGroup: 'Продвижение и реклама',
                serviceNames: ['OperationMarketplaceCostPerClick'],
            ),
            new self(
                code: 'ozon_premium_promotion',
                name: 'Продвижение Premium Ozon',
                widgetGroup: 'Продвижение и реклама',
                xlsxGroup: 'Продвижение и реклама',
                serviceNames: ['MarketplaceServicePremiumPromotion'],
                operationTypes: [
                    'OperationSubscriptionPremiumPlus',
                    'Подписка Premium Plus',
                ],
            ),
            new self(
                code: 'ozon_premium_cashback',
                name: 'Бонусы продавца Premium Ozon',
                widgetGroup: 'Продвижение и реклама',
                xlsxGroup: 'Продвижение и реклама',
                serviceNames: [
                    'MarketplaceServicePremiumCashbackIndividualPoints',
                    'MarketplaceServiceItemElectronicServicesPremiumCashbackIndividualPoints',
                    'OperationMarketplaceServicePremiumCashbackIndividualPoints',
                ],
            ),
            new self(
                code: 'ozon_reviews',
                name: 'Приобретение отзывов Ozon',
                widgetGroup: 'Продвижение и реклама',
                xlsxGroup: 'Продвижение и реклама',
                serviceNames: ['MarketplaceSaleReviewsItem'],
                operationTypes: [
                    'OperationPointsForReviews',
                    'Баллы за отзывы',
                ],
            ),
            new self(
                code: 'ozon_seller_bonus',
                name: 'Бонусы продавца (рассылки) Ozon',
                widgetGroup: 'Продвижение и реклама',
                xlsxGroup: 'Продвижение и реклама',
                operationTypes: [
                    'OperationMarketplaceServicePremiumCashbackBonusAccrual',
                    'Бонусы продавца - рассылка',
                ],
            ),
            new self(
                code: 'ozon_marketing_action',
                name: 'Маркетинговые акции Ozon',
                widgetGroup: 'Продвижение и реклама',
                xlsxGroup: 'Продвижение и реклама',
                serviceNames: ['MarketplaceMarketingActionCostItem'],
                operationTypes: ['OperationPromotionWithCostPerOrder'],
            ),
            new self(
                code: 'ozon_stars_membership',
                name: 'Звёздные товары Ozon',
                widgetGroup: 'Продвижение и реклама',
                xlsxGroup: 'Продвижение и реклама',
                serviceNames: ['ItemAgentServiceStarsMembership'],
            ),

            // =================================================================
            // Другие услуги и штрафы
            // =================================================================
            new self(
                code: 'ozon_package_materials',
                name: 'Упаковочные материалы Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Услуги FBO',
                serviceNames: ['MarketplaceServiceItemPackageMaterialsProvision'],
            ),
            new self(
                code: 'ozon_disposal',
                name: 'Утилизация товара Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                serviceNames: ['MarketplaceServiceItemDisposalDetailed'],
                operationTypes: [
                    'DisposalReasonDamagedPackaging',
                    'DisposalReasonScattered',
                    'DisposalReasonFailedToPickupOnTime',
                ],
            ),
            new self(
                code: 'ozon_ovh_processing',
                name: 'Дополнительная обработка ОВХ Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                serviceNames: [
                    'MarketplaceServiceVolumeWeightCharacsProcessing',
                    'OperationMarketplaceServiceVolumeWeightCharacsProcessing',
                ],
            ),
            new self(
                code: 'ozon_penalty_undeliverable',
                name: 'Удержание за недовложение Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                serviceNames: ['OperationMarketplaceWithHoldingForUndeliverableGoods'],
                operationTypes: [
                    'DefectRateDetailed',
                    'OperationMarketplaceModerationFine',
                    'OperationModerationProhibitedContent',
                    'Модерация запрещённого контента',
                    'Обработка операционных ошибок продавца: отгрузка в нерекомендованный слот',
                ],
            ),
            new self(
                code: 'ozon_marking',
                name: 'Обязательная маркировка Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                serviceNames: ['MarketplaceServiceItemMarkingItems'],
            ),
            new self(
                code: 'ozon_agency_fee',
                name: 'Агентская услуга 3PL Global Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                serviceNames: ['OperationMarketplaceAgencyFeeAggregator3PLGlobal'],
            ),
            new self(
                code: 'ozon_early_payment',
                name: 'Досрочная выплата Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                serviceNames: ['OperationMarketplaceServiceEarlyPaymentAccrual'],
            ),
            new self(
                code: 'ozon_flexible_payment',
                name: 'Гибкий график выплат Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                serviceNames: ['MarketplaceServiceItemFlexiblePaymentSchedule'],
            ),
            new self(
                code: 'ozon_installment',
                name: 'Продажа в рассрочку Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                serviceNames: ['MarketplaceServiceItemInstallment'],
            ),
            new self(
                code: 'ozon_premium_correction',
                name: 'Корректировка премии Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                operationTypes: ['Корректировка суммы акта о премии'],
            ),
            new self(
                code: 'ozon_service_correction',
                name: 'Корректировка стоимости услуг Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
                operationTypes: ['Корректировки стоимости услуг'],
            ),
            new self(
                code: 'ozon_other_service',
                name: 'Прочие услуги Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Другие услуги и штрафы',
            ),

            // =================================================================
            // Компенсации и декомпенсации (xlsx) / Другие услуги и штрафы (widget)
            // =================================================================
            new self(
                code: 'ozon_compensation',
                name: 'Компенсации и декомпенсации Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Компенсации и декомпенсации',
                operationTypes: ['AccrualInternalClaim'],
            ),
            new self(
                code: 'ozon_decompensation',
                name: 'Декомпенсация Ozon',
                widgetGroup: 'Другие услуги и штрафы',
                xlsxGroup: 'Компенсации и декомпенсации',
            ),
        ];

        return $cache;
    }

    // -------------------------------------------------------------------------
    // Lookup by code
    // -------------------------------------------------------------------------

    /**
     * @return array<string, OzonCostCategory> code => category
     */
    public static function byCode(): array
    {
        /** @var array<string, OzonCostCategory>|null $map */
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach (self::all() as $c) {
                $map[$c->code] = $c;
            }
        }

        return $map;
    }

    public static function findByCode(string $code): ?self
    {
        return self::byCode()[$code] ?? null;
    }

    // -------------------------------------------------------------------------
    // Lookup by service name (services[].name in Ozon API)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, OzonCostCategory> serviceName => category
     */
    private static function serviceNameIndex(): array
    {
        /** @var array<string, OzonCostCategory>|null $index */
        static $index = null;

        if ($index === null) {
            $index = [];
            foreach (self::all() as $c) {
                foreach ($c->serviceNames as $name) {
                    $index[$name] = $c;
                }
            }
        }

        return $index;
    }

    public static function findByServiceName(string $serviceName): ?self
    {
        return self::serviceNameIndex()[$serviceName] ?? null;
    }

    // -------------------------------------------------------------------------
    // Lookup by operation type (operation_type / operation_type_name)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, OzonCostCategory> operationType => category
     */
    private static function operationTypeIndex(): array
    {
        /** @var array<string, OzonCostCategory>|null $index */
        static $index = null;

        if ($index === null) {
            $index = [];
            foreach (self::all() as $c) {
                foreach ($c->operationTypes as $opType) {
                    $index[$opType] = $c;
                }
            }
        }

        return $index;
    }

    public static function findByOperationType(string $operationType): ?self
    {
        return self::operationTypeIndex()[$operationType] ?? null;
    }
}
