<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\PurchasePriceTimelinePolicy;
use PHPUnit\Framework\TestCase;

final class PurchasePriceTimelinePolicyTest extends TestCase
{
    public function testAssertNoOverlapWithNextAllowsWhenNextDateIsMissing(): void
    {
        $policy = new PurchasePriceTimelinePolicy();

        $policy->assertNoOverlapWithNext(null, new \DateTimeImmutable('2025-01-10'));

        self::assertTrue(true);
    }

    public function testAssertNoOverlapWithNextAllowsWhenNewDateIsBeforeNextDate(): void
    {
        $policy = new PurchasePriceTimelinePolicy();

        $policy->assertNoOverlapWithNext(
            new \DateTimeImmutable('2025-01-11'),
            new \DateTimeImmutable('2025-01-10')
        );

        self::assertTrue(true);
    }

    public function testAssertNoOverlapWithNextFailsWhenDatesAreEqual(): void
    {
        $policy = new PurchasePriceTimelinePolicy();
        $nextFrom = new \DateTimeImmutable('2025-01-10');
        $newFrom = new \DateTimeImmutable('2025-01-10');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя установить цену с даты 2025-01-10, потому что уже есть цена начиная с 2025-01-10.');

        $policy->assertNoOverlapWithNext($nextFrom, $newFrom);
    }

    public function testAssertNoOverlapWithNextFailsWhenNewDateIsAfterNextDate(): void
    {
        $policy = new PurchasePriceTimelinePolicy();
        $nextFrom = new \DateTimeImmutable('2025-01-10');
        $newFrom = new \DateTimeImmutable('2025-01-11');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя установить цену с даты 2025-01-11, потому что уже есть цена начиная с 2025-01-10.');

        $policy->assertNoOverlapWithNext($nextFrom, $newFrom);
    }
}
