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
        self::assertSame('Неизвестные категории Ozon', $unknown->group);
        self::assertSame(['999999'], $unknown->typeIds);
    }
}
