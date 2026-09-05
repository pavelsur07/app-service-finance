<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategory;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
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

        $stockInsurance = OzonAccrualCategory::findByTypeId('76');
        self::assertNotNull($stockInsurance);
        self::assertSame('ozon_stock_insurance', $stockInsurance->code);
        self::assertSame(TransactionType::FEE, $stockInsurance->transactionType);

        $customerReviews = OzonAccrualCategory::findByOzonName('CustomerReviews');
        self::assertNotNull($customerReviews);
        self::assertSame('ozon_customer_reviews', $customerReviews->code);
        self::assertSame('Начисления за отзывы клиентов', $customerReviews->label);
        self::assertSame('Продвижение и реклама', $customerReviews->group);
        self::assertSame(TransactionType::ADVERTISING, $customerReviews->transactionType);
    }

    public function testFindsObservedInternalOzonExternalCodes(): void
    {
        $expectedCodes = [
            'Acquiring' => 'ozon_acquiring',
            'AcceleratedReviewCollection' => 'ozon_accelerated_reviews',
            'BrandCommission' => 'ozon_brand_commission',
            'Compensation' => 'ozon_compensation',
            'CrossDock' => 'ozon_cross_docking',
            'CustomerReviews' => 'ozon_customer_reviews',
            'DefectFineComplaint' => 'ozon_defect_fine_complaint',
            'DefectFineErrors' => 'ozon_other_services',
            'DefectFineShipmentDelayRate' => 'ozon_defect_fine_shipment_delay',
            'DeliveryToHandoverPlaceByOzon' => 'ozon_delivery_to_pickup_ozon',
            'Disposal' => 'ozon_disposal',
            'Drop-Off' => 'ozon_drop_off',
            'EarlyPayment' => 'ozon_early_payout',
            'InternetSiteAdvertising' => 'ozon_site_advertising',
            'ItemCompensation' => 'ozon_item_compensation',
            'Installment' => 'ozon_installment',
            'ItemPacking' => 'ozon_partner_packaging',
            'LabelBrandVerified' => 'ozon_brand_verification_labeling',
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
            'StockInsurance' => 'ozon_stock_insurance',
            'TemporaryPlacement' => 'ozon_temporary_partner_storage',
            'TemporaryPlacementsAgent' => 'ozon_temporary_partner_storage',
        ];

        foreach ($expectedCodes as $ozonExternalCode => $expectedCode) {
            $category = OzonAccrualCategory::forTypedFee(null, null, TransactionType::FEE, $ozonExternalCode);

            self::assertTrue($category->known, sprintf('Ozon external code "%s" must not resolve as unknown.', $ozonExternalCode));
            self::assertSame($expectedCode, $category->code, sprintf('Unexpected category for Ozon external code "%s".', $ozonExternalCode));
        }
    }

    /**
     * Оба кода пришли из прода: LabelBrandVerified с 2026-08-02 (35 строк, 52 500 ₽),
     * Installment с 2026-08-20 (1 строка). Их не было в каталоге, поэтому они висели
     * в статусе new и каждую ночь роняли health-гейт daily-maintenance.
     */
    public function testAugust2026OzonCodesResolveToOwnCategories(): void
    {
        $brandVerified = OzonAccrualCategory::forTypedFee(null, null, TransactionType::FEE, 'LabelBrandVerified');
        self::assertTrue($brandVerified->known);
        self::assertSame('ozon_brand_verification_labeling', $brandVerified->code);
        self::assertSame('Маркировка проверенного бренда', $brandVerified->label);
        self::assertSame('Другие услуги и штрафы', $brandVerified->group);
        self::assertSame(TransactionType::FEE, $brandVerified->transactionType);

        $installment = OzonAccrualCategory::forTypedFee(null, null, TransactionType::FEE, 'Installment');
        self::assertTrue($installment->known);
        self::assertSame('ozon_installment', $installment->code);
        self::assertSame('Рассрочка', $installment->label);
        self::assertSame('Другие услуги и штрафы', $installment->group);
        self::assertSame(TransactionType::FEE, $installment->transactionType);

        // Обе категории обязаны отличаться от соседей, а не сливаться с ними.
        self::assertNotSame($brandVerified->code, OzonAccrualCategory::findByOzonName('LabelOriginal')?->code);
        self::assertNotSame($installment->code, OzonAccrualCategory::findByOzonName('EarlyPayment')?->code);
    }

    public function testNameOnlyTypedFeeStaysUnknown(): void
    {
        $category = OzonAccrualCategory::forTypedFee(null, 'Acquiring', TransactionType::FEE);

        self::assertFalse($category->known);
        self::assertSame('Требует классификации', $category->group);
    }

    public function testExternalCodeHeuristicRejectsDisplayAliases(): void
    {
        self::assertTrue(OzonAccrualCategoryTaxonomyResolver::looksLikeExternalCode('Acquiring'));
        self::assertTrue(OzonAccrualCategoryTaxonomyResolver::looksLikeExternalCode('Drop-Off'));
        self::assertFalse(OzonAccrualCategoryTaxonomyResolver::looksLikeExternalCode('Логистика Ozon'));
        self::assertFalse(OzonAccrualCategoryTaxonomyResolver::looksLikeExternalCode('Упаковка товара партнерами'));
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
