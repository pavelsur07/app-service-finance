<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Application\Service;

use App\MarketplaceAnalytics\Application\Service\MarketplaceCostAnalyticsGroupResolver;
use PHPUnit\Framework\TestCase;

final class MarketplaceCostAnalyticsGroupResolverTest extends TestCase
{
    private MarketplaceCostAnalyticsGroupResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MarketplaceCostAnalyticsGroupResolver();
    }

    public function testWbCommissionGroupsAndBucket(): void
    {
        $this->assertSame('Вознаграждение', $this->resolver->resolveWidgetGroup('wildberries', 'commission', ''));
        $this->assertSame('Вознаграждение', $this->resolver->resolveBreakdownGroup('wildberries', 'commission', ''));
        $this->assertSame('commission', $this->resolver->resolveUnitBucket('wildberries', 'commission', ''));
    }

    public function testWbLogisticsDeliveryGroupsAndBucket(): void
    {
        $this->assertSame('Услуги доставки и FBO', $this->resolver->resolveWidgetGroup('wildberries', 'logistics_delivery', ''));
        $this->assertSame('Услуги доставки', $this->resolver->resolveBreakdownGroup('wildberries', 'logistics_delivery', ''));
        $this->assertSame('logistics', $this->resolver->resolveUnitBucket('wildberries', 'logistics_delivery', ''));
    }

    public function testWbWarehouseLogisticsBreakdownAndBucket(): void
    {
        $this->assertSame('Услуги FBO', $this->resolver->resolveBreakdownGroup('wildberries', 'warehouse_logistics', ''));
        $this->assertSame('logistics', $this->resolver->resolveUnitBucket('wildberries', 'warehouse_logistics', ''));
    }

    public function testWbAcquiringGroupAndOtherBucket(): void
    {
        $this->assertSame('Услуги партнёров', $this->resolver->resolveWidgetGroup('wildberries', 'acquiring', ''));
        $this->assertSame('other', $this->resolver->resolveUnitBucket('wildberries', 'acquiring', ''));
    }

    public function testWbPromotionCodesAndOtherBucket(): void
    {
        $this->assertSame('Продвижение и реклама', $this->resolver->resolveWidgetGroup('wildberries', 'wb_okazanie_uslug_wb_prodvizhenie', ''));
        $this->assertSame('other', $this->resolver->resolveUnitBucket('wildberries', 'wb_okazanie_uslug_wb_prodvizhenie', ''));

        $this->assertSame('Продвижение и реклама', $this->resolver->resolveWidgetGroup('wildberries', 'wb_spisanie_za_otzyv', ''));
        $this->assertSame('other', $this->resolver->resolveUnitBucket('wildberries', 'wb_spisanie_za_otzyv', ''));
    }

    public function testWbLoyaltyCompensationBreakdownAndBucket(): void
    {
        $this->assertSame('Компенсации и декомпенсации', $this->resolver->resolveBreakdownGroup('wildberries', 'wb_loyalty_discount_compensation', ''));
        $this->assertSame('other', $this->resolver->resolveUnitBucket('wildberries', 'wb_loyalty_discount_compensation', ''));
    }

    public function testUnknownWbCodeFallsBackToDefault(): void
    {
        $this->assertSame('Другие услуги и штрафы', $this->resolver->resolveWidgetGroup('wildberries', 'unknown_code', ''));
        $this->assertSame('Другие услуги и штрафы', $this->resolver->resolveBreakdownGroup('wildberries', 'unknown_code', ''));
        $this->assertSame('other', $this->resolver->resolveUnitBucket('wildberries', 'unknown_code', ''));
    }

    public function testOzonSaleCommissionIsCommissionBucket(): void
    {
        $this->assertSame('commission', $this->resolver->resolveUnitBucket('ozon', 'ozon_sale_commission', ''));
    }

    public function testOzonDeliveryGroupIsLogisticsBucket(): void
    {
        $this->assertSame('logistics', $this->resolver->resolveUnitBucket('ozon', 'ozon_logistic_delivery', ''));
    }

    public function testUnknownMarketplaceAndNullFallbackDoNotFail(): void
    {
        $this->assertSame('other', $this->resolver->resolveUnitBucket('unknown', 'unknown_code', ''));
        $this->assertSame('Другие услуги и штрафы', $this->resolver->resolveWidgetGroup(null, 'unknown_code', ''));
    }
}
