<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\Domain\Service\UnitEconomicsAggregationPolicy;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\Domain\ValueObject\CostBreakdown;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;
use App\Tests\Builders\MarketplaceAnalytics\ListingDailySnapshotBuilder;
use PHPUnit\Framework\TestCase;

final class UnitEconomicsAggregationPolicyTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const LISTING_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private ListingDailySnapshotRepositoryInterface $snapshotRepository;
    private UnitEconomicsAggregationPolicy $policy;
    private AnalysisPeriod $period;

    protected function setUp(): void
    {
        $this->snapshotRepository = $this->createMock(ListingDailySnapshotRepositoryInterface::class);
        $this->policy = new UnitEconomicsAggregationPolicy($this->snapshotRepository);
        $this->period = new AnalysisPeriod(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-15'),
        );
    }

    public function testGroupsByListingId(): void
    {
        $snapshot1 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(1)
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-10'))
            ->withRevenue('100.00')
            ->withSalesQuantity(1)
            ->build();

        $snapshot2 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(2)
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-11'))
            ->withRevenue('200.00')
            ->withSalesQuantity(2)
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([$snapshot1, $snapshot2]);

        $result = $this->policy->aggregateForPeriod(self::COMPANY_ID, $this->period, null);

        $this->assertCount(1, $result);
        $this->assertSame(self::LISTING_ID, $result[0]->listingId);
    }

    public function testRevenueSummedCorrectly(): void
    {
        $snapshot1 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(1)
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-10'))
            ->withRevenue('100.00')
            ->withSalesQuantity(1)
            ->withAvgSalePrice('100.00')
            ->build();

        $snapshot2 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(2)
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-11'))
            ->withRevenue('200.00')
            ->withSalesQuantity(2)
            ->withAvgSalePrice('100.00')
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([$snapshot1, $snapshot2]);

        $result = $this->policy->aggregateForPeriod(self::COMPANY_ID, $this->period, null);

        $this->assertSame('300.00', $result[0]->revenue);
    }

    public function testProfitTotalCalculatedWhenAllHaveCostPrice(): void
    {
        $costBreakdown = (new CostBreakdown(
            logisticsTo: '10.00',
            logisticsBack: '0.00',
            storage: '5.00',
            advertisingCpc: '0.00',
            advertisingOther: '0.00',
            advertisingExternal: '0.00',
            commission: '15.00',
            other: '0.00',
        ))->toArray();

        $snapshot1 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(1)
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-10'))
            ->withRevenue('500.00')
            ->withSalesQuantity(5)
            ->withAvgSalePrice('100.00')
            ->withCostPrice('50.00')
            ->withTotalCostPrice('250.00')
            ->withCostBreakdown($costBreakdown)
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([$snapshot1]);

        $result = $this->policy->aggregateForPeriod(self::COMPANY_ID, $this->period, null);

        $this->assertNotNull($result[0]->profitTotal);
    }

    public function testProfitTotalNullWhenAnyCostPriceMissing(): void
    {
        $snapshot1 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(1)
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-10'))
            ->withRevenue('100.00')
            ->withSalesQuantity(1)
            ->withAvgSalePrice('100.00')
            ->withCostPrice('50.00')
            ->withTotalCostPrice('50.00')
            ->build();

        $snapshot2 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(2)
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-11'))
            ->withRevenue('200.00')
            ->withSalesQuantity(2)
            ->withAvgSalePrice('100.00')
            ->withoutCostPrice()
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([$snapshot1, $snapshot2]);

        $result = $this->policy->aggregateForPeriod(self::COMPANY_ID, $this->period, null);

        $this->assertNull($result[0]->profitTotal);
    }

    public function testDrrCalculation(): void
    {
        $costBreakdown = (new CostBreakdown(
            logisticsTo: '0.00',
            logisticsBack: '0.00',
            storage: '0.00',
            advertisingCpc: '10.00',
            advertisingOther: '0.00',
            advertisingExternal: '0.00',
            commission: '0.00',
            other: '0.00',
        ))->toArray();

        $snapshot = ListingDailySnapshotBuilder::aSnapshot()
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-10'))
            ->withRevenue('100.00')
            ->withSalesQuantity(1)
            ->withAvgSalePrice('100.00')
            ->withCostPrice('50.00')
            ->withTotalCostPrice('50.00')
            ->withCostBreakdown($costBreakdown)
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([$snapshot]);

        $result = $this->policy->aggregateForPeriod(self::COMPANY_ID, $this->period, null);

        // totalAdvertising=10.00, revenue=100.00 → drr = 10.00/100.00 * 100 = 10.0
        $this->assertSame(10.0, $result[0]->drr);
    }

    public function testPurchaseRateCalculation(): void
    {
        $snapshot = ListingDailySnapshotBuilder::aSnapshot()
            ->withListingId(self::LISTING_ID)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-10'))
            ->withRevenue('100.00')
            ->withSalesQuantity(1)
            ->withAvgSalePrice('100.00')
            ->withOrdersQuantity(10)
            ->withDeliveredQuantity(8)
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([$snapshot]);

        $result = $this->policy->aggregateForPeriod(self::COMPANY_ID, $this->period, null);

        // deliveredQuantity=8, ordersQuantity=10 → purchaseRate = 8/10 * 100 = 80.0
        $this->assertSame(80.0, $result[0]->purchaseRate);
    }

    public function testReturnsEmptyArrayWhenNoSnapshots(): void
    {
        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([]);

        $result = $this->policy->aggregateForPeriod(self::COMPANY_ID, $this->period, null);

        $this->assertSame([], $result);
    }

    public function testSortingCostPriceFirst(): void
    {
        $listingWithout = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $listingWith = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

        $snapshotWithout = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(1)
            ->withListingId($listingWithout)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-10'))
            ->withRevenue('100.00')
            ->withSalesQuantity(1)
            ->withAvgSalePrice('100.00')
            ->withoutCostPrice()
            ->build();

        $snapshotWith = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(2)
            ->withListingId($listingWith)
            ->withSnapshotDate(new \DateTimeImmutable('2026-01-10'))
            ->withRevenue('200.00')
            ->withSalesQuantity(2)
            ->withAvgSalePrice('100.00')
            ->withCostPrice('50.00')
            ->withTotalCostPrice('100.00')
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([$snapshotWithout, $snapshotWith]);

        $result = $this->policy->aggregateForPeriod(self::COMPANY_ID, $this->period, null);

        $this->assertCount(2, $result);
        $this->assertSame($listingWith, $result[0]->listingId);
        $this->assertSame($listingWithout, $result[1]->listingId);
    }
}
