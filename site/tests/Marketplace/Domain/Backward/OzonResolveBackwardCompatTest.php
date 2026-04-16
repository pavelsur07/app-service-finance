<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Domain\Backward;

use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Backward-compatibility тест: гарантирует что resolve() возвращает
 * те же category codes, что и до рефакторинга OzonCostCategory.
 *
 * EXPECTED_MAPPING — это эталонный снимок MAP из OzonServiceCategoryMap
 * на момент коммита 5c30a4b (2026-04-16, VERSION 2026-04-16.2).
 *
 * Если маппинг изменится — тест покажет какой именно ключ разошёлся.
 */
final class OzonResolveBackwardCompatTest extends TestCase
{
    /**
     * Эталонный маппинг: service_name => expected category code.
     * null = нулевой маркер (пропуск записи).
     *
     * @var array<string, string|null>
     */
    private const EXPECTED_MAPPING = [
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
        'MarketplaceServiceItemRedistributionDropOffApvz'        => 'ozon_dropoff_apvz',
        'MarketplaceServiceItemDropoffSC'                        => 'ozon_dropoff_sc',
        'MarketplaceServiceItemDropoffPPZ'                       => 'ozon_dropoff_ppz',

        // === ОБРАБОТКА ВОЗВРАТОВ ===
        'MarketplaceServiceItemRedistributionReturnsPVZ'         => 'ozon_return_pvz',
        'MarketplaceServiceItemReturnPartGoodsCustomer'          => 'ozon_return_partial',
        'MarketplaceNotDeliveredCostItem'                        => 'ozon_return_not_delivered',
        'MarketplaceReturnAfterDeliveryCostItem'                 => 'ozon_return_after_delivery',
        'MarketplaceReturnStorageServiceAtThePickupPointFbsItem' => 'ozon_return_storage_pvz',
        'MarketplaceReturnStorageServiceInTheWarehouseFbsItem'   => 'ozon_return_storage_wh',

        // === НУЛЕВЫЕ МАРКЕРЫ ===
        'MarketplaceServiceItemReturnNotDelivToCustomer'         => null,
        'MarketplaceServiceItemReturnAfterDelivToCustomer'       => null,

        // === УПАКОВКА ===
        'MarketplaceServiceItemPackageMaterialsProvision'        => 'ozon_package_materials',
        'MarketplaceServiceItemPackageRedistribution'            => 'ozon_package_labor',

        // === ХРАНЕНИЕ ===
        'OperationMarketplaceServiceStorage'                     => 'ozon_storage',
        'MarketplaceServiceItemTemporaryStorageRedistribution'   => 'ozon_storage_partner',
        'OperationMarketplaceItemTemporaryStorageRedistribution' => 'ozon_storage_partner',

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
        'OperationMarketplaceServicePremiumCashbackIndividualPoints' => 'ozon_premium_cashback',
        'ItemAgentServiceStarsMembership'                        => 'ozon_stars_membership',

        // === ФИНАНСОВЫЕ УСЛУГИ ===
        'OperationMarketplaceServiceEarlyPaymentAccrual'         => 'ozon_early_payment',
        'MarketplaceServiceItemFlexiblePaymentSchedule'          => 'ozon_flexible_payment',
        'MarketplaceServiceItemInstallment'                      => 'ozon_installment',

        // === ШТРАФЫ / УДЕРЖАНИЯ ===
        'OperationMarketplaceWithHoldingForUndeliverableGoods'   => 'ozon_penalty_undeliverable',

        // === КОМИССИИ ===
        'MarketplaceServiceBrandCommission'                      => 'ozon_brand_commission',

        // === ПРОЧЕЕ ===
        'MarketplaceServiceItemMarkingItems'                     => 'ozon_marking',
        'MarketplaceServiceItemReturnFromStock'                  => 'ozon_return_from_stock',
        'MarketplaceServiceSellerReturnsCargoAssortment'         => 'ozon_return_from_stock',
        'OperationMarketplaceAgencyFeeAggregator3PLGlobal'       => 'ozon_agency_fee',
        'MarketplaceServiceItemDisposalDetailed'                 => 'ozon_disposal',
        'MarketplaceServiceProductMovementFromWarehouse'         => 'ozon_logistic_pickup',
        'MarketplaceServiceVolumeWeightCharacsProcessing'        => 'ozon_ovh_processing',
        'OperationMarketplaceServiceVolumeWeightCharacsProcessing' => 'ozon_ovh_processing',

        // === АНГЛИЙСКИЕ operation_type ===
        'AccrualInternalClaim'                                   => 'ozon_compensation',
        'DisposalReasonDamagedPackaging'                         => 'ozon_disposal',
        'DisposalReasonScattered'                                => 'ozon_disposal',
        'DisposalReasonFailedToPickupOnTime'                     => 'ozon_disposal',
        'OperationReturnGoodsFBSofRMS'                           => 'ozon_return_delivery',
        'OperationSellerReturnsCargoAssortmentInvalid'            => 'ozon_return_delivery',
        'OperationSellerReturnsCargoAssortmentValid'              => 'ozon_return_delivery',
        'SellerReturnsDeliveryToPickupPoint'                     => 'ozon_return_pvz',
        'OperationMarketplaceServicePremiumCashbackBonusAccrual' => 'ozon_seller_bonus',
        'OperationPointsForReviews'                              => 'ozon_reviews',
        'OperationMarketplaceSupplyExpirationDateProcessing'     => 'ozon_supply_additional',
        'OperationPromotionWithCostPerOrder'                     => 'ozon_marketing_action',
        'OperationSubscriptionPremiumPlus'                       => 'ozon_premium_promotion',
        'DefectRateDetailed'                                     => 'ozon_penalty_undeliverable',
        'MarketplaceServiceItemReplenishment'                     => 'ozon_warehouse_movement',
        'OperationMarketplaceWarehouseToWarehouseMovement'       => 'ozon_warehouse_movement',
        'OperationMarketplaceModerationFine'                     => 'ozon_penalty_undeliverable',
        'OperationModerationProhibitedContent'                   => 'ozon_penalty_undeliverable',
        'OperationMarketplaceSupplyDefectProcessing'             => 'ozon_supply_additional',
        'OperationMarketplaceServiceProcessingSpoilageSurplus'   => 'ozon_supply_additional',

        // === РУССКОЯЗЫЧНЫЕ НАЗВАНИЯ ===
        'Подписка Premium Plus'                                  => 'ozon_premium_promotion',
        'Бонусы продавца - рассылка'                             => 'ozon_seller_bonus',
        'Баллы за отзывы'                                        => 'ozon_reviews',
        'Перемещение товаров между складами Ozon'                => 'ozon_warehouse_movement',
        'Обработка сроков годности на FBO'                       => 'ozon_supply_additional',
        'Модерация запрещённого контента'                        => 'ozon_penalty_undeliverable',
        'Обработка операционных ошибок продавца: отгрузка в нерекомендованный слот' => 'ozon_penalty_undeliverable',
        'Обработка брака с приемки'                              => 'ozon_supply_additional',
        'Временное размещение товара партнерами'                  => 'ozon_storage_partner',
        'Корректировка суммы акта о премии'                      => 'ozon_premium_correction',
        'Корректировки стоимости услуг'                          => 'ozon_service_correction',
    ];

    public function test_resolve_returns_same_mapping_as_before_refactoring(): void
    {
        $logger = new NullLogger();
        $failures = [];

        foreach (self::EXPECTED_MAPPING as $key => $expectedCode) {
            $actual = OzonServiceCategoryMap::resolve($key, $logger);

            if ($actual !== $expectedCode) {
                $failures[] = sprintf(
                    '  key="%s": expected=%s, actual=%s',
                    $key,
                    var_export($expectedCode, true),
                    var_export($actual, true),
                );
            }
        }

        $this->assertSame([], $failures, sprintf(
            "Mapping changed for %d key(s):\n%s",
            count($failures),
            implode("\n", $failures),
        ));
    }

    public function test_expected_mapping_count(): void
    {
        $this->assertCount(
            88,
            self::EXPECTED_MAPPING,
            'EXPECTED_MAPPING should contain all 88 entries from the baseline MAP',
        );
    }
}
