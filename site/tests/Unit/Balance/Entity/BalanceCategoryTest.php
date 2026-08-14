<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Entity;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Exception\BalanceDepthExceededException;
use App\Tests\Builders\Balance\BalanceCategoryBuilder;
use PHPUnit\Framework\TestCase;

final class BalanceCategoryTest extends TestCase
{
    public function testNewCategoryHasLevelOne(): void
    {
        $category = BalanceCategoryBuilder::aBalanceCategory()->build();

        self::assertSame(1, $category->getLevel());
    }

    public function testChildLevelIsParentLevelPlusOne(): void
    {
        $parent = BalanceCategoryBuilder::aBalanceCategory()->build();
        $child = BalanceCategoryBuilder::aBalanceCategory()->withParent($parent)->build();

        self::assertSame(2, $child->getLevel());
    }

    public function testDepthLimitThrowsBalanceDepthExceededException(): void
    {
        $this->expectException(BalanceDepthExceededException::class);

        $root = BalanceCategoryBuilder::aBalanceCategory()->build();
        $level2 = BalanceCategoryBuilder::aBalanceCategory()->withParent($root)->build();
        $level3 = BalanceCategoryBuilder::aBalanceCategory()->withParent($level2)->build();
        $level4 = BalanceCategoryBuilder::aBalanceCategory()->withParent($level3)->build();
        $level5 = BalanceCategoryBuilder::aBalanceCategory()->withParent($level4)->build();

        BalanceCategoryBuilder::aBalanceCategory()->withParent($level5)->build();
    }

    public function testSetParentToNullResetsLevel(): void
    {
        $parent = BalanceCategoryBuilder::aBalanceCategory()->build();
        $child = BalanceCategoryBuilder::aBalanceCategory()->withParent($parent)->build();

        $child->setParent(null);

        self::assertSame(1, $child->getLevel());
    }

    public function testTimestampsAreSetOnCreation(): void
    {
        $before = new \DateTimeImmutable('-1 second');
        $category = BalanceCategoryBuilder::aBalanceCategory()->build();
        $after = new \DateTimeImmutable('+1 second');

        self::assertGreaterThan($before, $category->getCreatedAt());
        self::assertLessThan($after, $category->getCreatedAt());
        self::assertEqualsWithDelta(
            $category->getCreatedAt()->getTimestamp(),
            $category->getUpdatedAt()->getTimestamp(),
            1,
        );
    }

    public function testUpdatedAtChangesOnSetter(): void
    {
        $category = BalanceCategoryBuilder::aBalanceCategory()->build();
        $originalUpdatedAt = $category->getUpdatedAt();

        sleep(1);
        $category->setName('New name');

        self::assertGreaterThan($originalUpdatedAt, $category->getUpdatedAt());
    }
}
