<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Application\DTO\CostDistributionResult;
use App\MarketplaceAds\Domain\Service\AdCostDistributor;
use PHPUnit\Framework\TestCase;

final class AdCostDistributorTest extends TestCase
{
    private AdCostDistributor $distributor;

    protected function setUp(): void
    {
        $this->distributor = new AdCostDistributor();
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы
    // -------------------------------------------------------------------------

    private function makeListing(string $id, string $parentSku = 'SKU-1'): array
    {
        return ['id' => $id, 'parentSku' => $parentSku];
    }

    private function sumCosts(array $results): string
    {
        $sum = '0.00';
        foreach ($results as $r) {
            $sum = bcadd($sum, $r->cost, 2);
        }

        return $sum;
    }

    private function sumShares(array $results): string
    {
        $sum = '0.00';
        foreach ($results as $r) {
            $sum = bcadd($sum, $r->sharePercent, 2);
        }

        return $sum;
    }

    private function sumImpressions(array $results): int
    {
        return array_sum(array_map(static fn(CostDistributionResult $r) => $r->impressions, $results));
    }

    private function sumClicks(array $results): int
    {
        return array_sum(array_map(static fn(CostDistributionResult $r) => $r->clicks, $results));
    }

    // -------------------------------------------------------------------------
    // Тесты
    // -------------------------------------------------------------------------

    public function testEmptyListingsReturnsEmpty(): void
    {
        $result = $this->distributor->distribute([], [], '100.00', 1000, 50);

        self::assertSame([], $result);
    }

    public function testSingleListingGetsFullAmount(): void
    {
        $results = $this->distributor->distribute(
            [$this->makeListing('A')],
            ['A' => 5],
            '99.99',
            500,
            30,
        );

        self::assertCount(1, $results);
        self::assertSame('A', $results[0]->listingId);
        self::assertSame('99.99', $results[0]->cost);
        self::assertSame('100.00', $results[0]->sharePercent);
        self::assertSame(500, $results[0]->impressions);
        self::assertSame(30, $results[0]->clicks);
    }

    public function testSingleListingNoSalesGetsFullAmount(): void
    {
        $results = $this->distributor->distribute(
            [$this->makeListing('A')],
            [],
            '50.00',
            200,
            10,
        );

        self::assertCount(1, $results);
        self::assertSame('50.00', $results[0]->cost);
        self::assertSame('100.00', $results[0]->sharePercent);
        self::assertSame(200, $results[0]->impressions);
        self::assertSame(10, $results[0]->clicks);
    }

    public function testTwoListingsEqualSalesGetFiftyPercent(): void
    {
        $results = $this->distributor->distribute(
            [$this->makeListing('A'), $this->makeListing('B')],
            ['A' => 10, 'B' => 10],
            '100.00',
            1000,
            100,
        );

        self::assertCount(2, $results);
        self::assertSame('50.00', $results[0]->cost);
        self::assertSame('50.00', $results[0]->sharePercent);
        self::assertSame(500, $results[0]->impressions);
        self::assertSame(50, $results[0]->clicks);
        self::assertSame('50.00', $results[1]->cost);
        self::assertSame('50.00', $results[1]->sharePercent);
    }

    public function testProportionalDistributionBySales(): void
    {
        // A:2 sales, B:1 sale → 2/3 and 1/3
        $results = $this->distributor->distribute(
            [$this->makeListing('A'), $this->makeListing('B')],
            ['A' => 2, 'B' => 1],
            '30.00',
            300,
            60,
        );

        self::assertCount(2, $results);
        self::assertSame('30.00', $this->sumCosts($results));
        self::assertSame('100.00', $this->sumShares($results));
        self::assertSame(300, $this->sumImpressions($results));
        self::assertSame(60, $this->sumClicks($results));

        // A gets ~2/3, B gets ~1/3
        self::assertSame('B', $results[1]->listingId);
        self::assertGreaterThan((float) $results[1]->cost, (float) $results[0]->cost);
    }

    public function testEqualDistributionWhenNoSales(): void
    {
        $results = $this->distributor->distribute(
            [$this->makeListing('A'), $this->makeListing('B'), $this->makeListing('C')],
            [],
            '10.00',
            100,
            30,
        );

        self::assertCount(3, $results);
        // Sums must match exactly
        self::assertSame('10.00', $this->sumCosts($results));
        self::assertSame('100.00', $this->sumShares($results));
        self::assertSame(100, $this->sumImpressions($results));
        self::assertSame(30, $this->sumClicks($results));
    }

    public function testRoundingCorrectionOnCostSum(): void
    {
        // 3 equal listings, cost = '10.00' — 10/3 cannot divide evenly
        $results = $this->distributor->distribute(
            [$this->makeListing('A'), $this->makeListing('B'), $this->makeListing('C')],
            [],
            '10.00',
            100,
            30,
        );

        // Total must be exact regardless of per-row rounding
        self::assertSame('10.00', $this->sumCosts($results));
        self::assertSame('100.00', $this->sumShares($results));
        self::assertSame(100, $this->sumImpressions($results));
        self::assertSame(30, $this->sumClicks($results));
    }

    public function testRoundingCorrectionOnLargerSet(): void
    {
        // 7 equal listings, cost = '100.00' — 100/7 repeating decimal
        $listings = [];
        for ($i = 1; $i <= 7; $i++) {
            $listings[] = $this->makeListing("L{$i}");
        }

        $results = $this->distributor->distribute(
            $listings,
            [],
            '100.00',
            700,
            70,
        );

        self::assertCount(7, $results);
        self::assertSame('100.00', $this->sumCosts($results));
        self::assertSame('100.00', $this->sumShares($results));
        self::assertSame(700, $this->sumImpressions($results));
        self::assertSame(70, $this->sumClicks($results));
    }

    public function testZeroCostProducesZeroDistribution(): void
    {
        $results = $this->distributor->distribute(
            [$this->makeListing('A'), $this->makeListing('B')],
            ['A' => 5, 'B' => 5],
            '0.00',
            0,
            0,
        );

        self::assertCount(2, $results);
        self::assertSame('0.00', $this->sumCosts($results));
        self::assertSame(0, $this->sumImpressions($results));
        self::assertSame(0, $this->sumClicks($results));
    }

    public function testReturnTypeIsArrayOfCostDistributionResult(): void
    {
        $results = $this->distributor->distribute(
            [$this->makeListing('A')],
            ['A' => 1],
            '10.00',
            100,
            10,
        );

        self::assertContainsOnlyInstancesOf(CostDistributionResult::class, $results);
    }

    public function testListingWithoutSalesEntryTreatedAsZero(): void
    {
        // Один листинг с продажами, другой отсутствует в карте — должен получить 0 продаж.
        $results = $this->distributor->distribute(
            [$this->makeListing('A'), $this->makeListing('B')],
            ['A' => 10],
            '50.00',
            100,
            20,
        );

        self::assertCount(2, $results);
        self::assertSame('A', $results[0]->listingId);
        self::assertSame('50.00', $results[0]->cost);
        self::assertSame('100.00', $results[0]->sharePercent);
        self::assertSame('B', $results[1]->listingId);
        self::assertSame('0.00', $results[1]->cost);
        self::assertSame('0.00', $results[1]->sharePercent);
    }
}
