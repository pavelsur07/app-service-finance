<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategory;
use App\Ingestion\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

final class OzonAccrualCategoryTest extends TestCase
{
    public function testRegistryHasUniqueCodesAndTypeIds(): void
    {
        $codes = [];
        $typeIds = [];

        foreach (OzonAccrualCategory::all() as $category) {
            self::assertNotSame('', $category->code);
            self::assertNotSame('', $category->label);
            self::assertNotSame('', $category->group);
            self::assertGreaterThan(0, $category->sortOrder);
            self::assertArrayNotHasKey($category->code, $codes, sprintf('Duplicate Ozon accrual category code "%s".', $category->code));
            $codes[$category->code] = true;

            foreach ($category->typeIds as $typeId) {
                self::assertArrayNotHasKey($typeId, $typeIds, sprintf('Duplicate Ozon accrual type_id "%s".', $typeId));
                $typeIds[$typeId] = $category->code;
            }

            foreach (array_merge([$category->label], $category->aliases) as $alias) {
                $resolved = OzonAccrualCategory::findByOzonName($alias);

                self::assertNotNull($resolved, sprintf('Ozon accrual category alias "%s" failed to resolve.', $alias));
                self::assertSame(
                    $category->code,
                    $resolved->code,
                    sprintf('Ozon accrual category alias "%s" resolved to "%s" instead of "%s".', $alias, $resolved->code, $category->code),
                );
            }
        }
    }

    public function testFindsCategoryByTypeIdAndAlias(): void
    {
        $logistics = OzonAccrualCategory::findByTypeId('29');
        self::assertNotNull($logistics);
        self::assertSame('ozon_logistics', $logistics->code);
        self::assertSame(TransactionType::LOGISTICS, $logistics->transactionType);

        $acquiring = OzonAccrualCategory::findByOzonName('Эквайринг Ozon');
        self::assertNotNull($acquiring);
        self::assertSame('ozon_acquiring', $acquiring->code);
        self::assertSame(TransactionType::ACQUIRING, $acquiring->transactionType);

        $partnerReturn = OzonAccrualCategory::findByOzonName('Обработка возвратов, отмен и невыкупов партнерами');
        self::assertNotNull($partnerReturn);
        self::assertSame('ozon_partner_return_processing', $partnerReturn->code);

        $crossDocking = OzonAccrualCategory::findByOzonName('Кросс-докинг Ozon');
        self::assertNotNull($crossDocking);
        self::assertSame('ozon_cross_docking', $crossDocking->code);

        $warehouseExport = OzonAccrualCategory::forTypedFee('77', null, TransactionType::FEE, 'ozon_warehouse_export');
        self::assertTrue($warehouseExport->known);
        self::assertSame('ozon_warehouse_export', $warehouseExport->code);
    }

    public function testFindsObservedInternalOzonTypeNames(): void
    {
        $expectedCodes = [
            'Acquiring' => 'ozon_acquiring',
            'AcceleratedReviewCollection' => 'ozon_accelerated_reviews',
            'BrandCommission' => 'ozon_brand_commission',
            'Compensation' => 'ozon_compensation',
            'CrossDock' => 'ozon_cross_docking',
            'DefectFineComplaint' => 'ozon_defect_fine_complaint',
            'DefectFineErrors' => 'ozon_other_services',
            'DefectFineShipmentDelayRate' => 'ozon_defect_fine_shipment_delay',
            'DeliveryToHandoverPlaceByOzon' => 'ozon_delivery_to_pickup_ozon',
            'Disposal' => 'ozon_disposal',
            'Drop-Off' => 'ozon_drop_off',
            'EarlyPayment' => 'ozon_early_payout',
            'InternetSiteAdvertising' => 'ozon_site_advertising',
            'ItemCompensation' => 'ozon_item_compensation',
            'ItemPacking' => 'ozon_partner_packaging',
            'LabelOriginal' => 'ozon_original_labeling',
            'Logistic' => 'ozon_logistics',
            'Marketing' => 'ozon_marketing',
            'PackageCost' => 'ozon_packaging_materials',
            'PackingFee' => 'ozon_partner_packaging',
            'PayPerClick' => 'ozon_cpc',
            'Placements' => 'ozon_partner_placement',
            'PremiumCashbackIndividualPoints' => 'ozon_other_services',
            'PremiumMailingCommission' => 'ozon_other_services',
            'PremiumSubscription' => 'ozon_premium_subscription',
            'Promotion' => 'ozon_promotion',
            'PushCampaign' => 'ozon_push_campaign',
            'ReturnFlowLogistic' => 'ozon_reverse_logistics',
            'RfbsServiceFee' => 'ozon_other_services',
            'SellerReturns' => 'ozon_partner_return_processing',
            'StarsMembership' => 'ozon_stars_membership',
            'TemporaryPlacement' => 'ozon_temporary_partner_storage',
            'TemporaryPlacementsAgent' => 'ozon_temporary_partner_storage',
        ];

        foreach ($expectedCodes as $ozonTypeName => $expectedCode) {
            $category = OzonAccrualCategory::forTypedFee(null, $ozonTypeName, TransactionType::FEE);

            self::assertTrue($category->known, sprintf('Ozon type name "%s" must not resolve as unknown.', $ozonTypeName));
            self::assertSame($expectedCode, $category->code, sprintf('Unexpected category for Ozon type name "%s".', $ozonTypeName));
        }
    }

    public function testFindsFieldCategoriesBySignedAmount(): void
    {
        self::assertSame('ozon_revenue', OzonAccrualCategory::forField('sale_amount', 100)?->code);
        self::assertSame('ozon_revenue_refund', OzonAccrualCategory::forField('sale_amount', -100)?->code);
        self::assertSame('ozon_discount_points', OzonAccrualCategory::forField('bonus', 100)?->code);
        self::assertSame('ozon_discount_points_refund', OzonAccrualCategory::forField('bonus', -100)?->code);
        self::assertSame('ozon_partner_programs', OzonAccrualCategory::forField('coinvestment', 100)?->code);
        self::assertSame('ozon_partner_programs_refund', OzonAccrualCategory::forField('coinvestment', -100)?->code);
        self::assertSame('ozon_sale_commission', OzonAccrualCategory::forField('commission', -100)?->code);
        self::assertSame('ozon_commission_refund', OzonAccrualCategory::forField('commission', 100)?->code);
    }

    public function testUnknownTypeKeepsFallbackAccountingType(): void
    {
        $unknown = OzonAccrualCategory::forTypedFee('999999', null, TransactionType::FEE);

        self::assertFalse($unknown->known);
        self::assertSame(TransactionType::FEE, $unknown->transactionType);
        self::assertSame('Требует классификации', $unknown->group);
        self::assertSame(['999999'], $unknown->typeIds);
    }
}
