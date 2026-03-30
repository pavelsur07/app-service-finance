<?php

declare(strict_types=1);

namespace App\Tests\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\Domain\Service\PortfolioSummaryPolicy;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;
use App\Tests\Builders\MarketplaceAnalytics\ListingDailySnapshotBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PortfolioSummaryPolicyTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private ListingDailySnapshotRepositoryInterface&MockObject $snapshotRepository;
    private PortfolioSummaryPolicy $policy;
    private AnalysisPeriod $period;

    protected function setUp(): void
    {
        $this->snapshotRepository = $this->createMock(ListingDailySnapshotRepositoryInterface::class);
        $this->policy = new PortfolioSummaryPolicy($this->snapshotRepository);
        $this->period = AnalysisPeriod::custom(
            new \DateTimeImmutable('-30 days'),
            new \DateTimeImmutable('yesterday'),
        );
    }

    public function testTotalRevenueIsSumOfAllSnapshots(): void
    {
        $snapshot1 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(1)
            ->withRevenue('1000.00')
            ->withSalesQuantity(1)
            ->build();

        $snapshot2 = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(2)
            ->withRevenue('2000.00')
            ->withSalesQuantity(2)
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturnOnConsecutiveCalls(
                [$snapshot1, $snapshot2], // current period
                [],                       // previous period
            );

        $result = $this->policy->calculate(self::COMPANY_ID, $this->period, null);

        $this->assertSame('3000.00', $result->totalRevenue);
    }

    public function testDeltasCalculatedWhenBothPeriodsHaveData(): void
    {
        $snapshotCurrent = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(1)
            ->withRevenue('3000.00')
            ->withSalesQuantity(1)
            ->build();

        $snapshotPrevious = ListingDailySnapshotBuilder::aSnapshot()
            ->withIndex(2)
            ->withRevenue('2000.00')
            ->withSalesQuantity(1)
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturnOnConsecutiveCalls(
                [$snapshotCurrent],  // current period
                [$snapshotPrevious], // previous period
            );

        $result = $this->policy->calculate(self::COMPANY_ID, $this->period, null);

        $this->assertSame('1000.00', $result->revenueDeltaAbsolute);
        $this->assertSame(50.0, $result->revenueDeltaPercent);
    }

    public function testDeltaPercentNullWhenPreviousPeriodEmpty(): void
    {
        $snapshotCurrent = ListingDailySnapshotBuilder::aSnapshot()
            ->withRevenue('3000.00')
            ->withSalesQuantity(1)
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturnOnConsecutiveCalls(
                [$snapshotCurrent], // current period
                [],                 // previous period empty
            );

        $result = $this->policy->calculate(self::COMPANY_ID, $this->period, null);

        $this->assertNull($result->revenueDeltaPercent);
        $this->assertNull($result->revenueDeltaAbsolute);
    }

    public function testAllZerosWhenBothPeriodsEmpty(): void
    {
        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturn([]);

        $result = $this->policy->calculate(self::COMPANY_ID, $this->period, null);

        $this->assertSame('0.00', $result->totalRevenue);
        $this->assertSame(0.0, $result->revenueDeltaPercent);
        $this->assertSame('0.00', $result->revenueDeltaAbsolute);
    }

    public function testMarginPercentNullWhenNoCostPrice(): void
    {
        $snapshot = ListingDailySnapshotBuilder::aSnapshot()
            ->withRevenue('1000.00')
            ->withSalesQuantity(1)
            ->withoutCostPrice()
            ->build();

        $this->snapshotRepository->method('findByCompanyAndPeriod')
            ->willReturnOnConsecutiveCalls(
                [$snapshot], // current period
                [],          // previous period
            );

        $result = $this->policy->calculate(self::COMPANY_ID, $this->period, null);

        $this->assertNull($result->marginPercent);
    }
}
