<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Domain\Policy;

use App\Balance\Domain\Policy\BalanceStructurePolicy;
use App\Balance\Exception\BalanceCategoryCycleException;
use App\Balance\Exception\BalanceDepthExceededException;
use App\Tests\Builders\Balance\BalanceCategoryBuilder;
use App\Tests\Builders\Balance\InMemoryBalanceCategoryRepository;
use PHPUnit\Framework\TestCase;

final class BalanceStructurePolicyTest extends TestCase
{
    public function testAllowsSettingRootParent(): void
    {
        $repository = new InMemoryBalanceCategoryRepository();
        $policy = new BalanceStructurePolicy($repository);
        $category = BalanceCategoryBuilder::aBalanceCategory()->build();

        $policy->assertCanSetParent($category, null, $category->getCompanyId());

        self::assertNull($category->getParent());
        self::assertSame(1, $category->getLevel());
    }

    public function testThrowsOnSelfParent(): void
    {
        $this->expectException(BalanceCategoryCycleException::class);

        $repository = new InMemoryBalanceCategoryRepository();
        $policy = new BalanceStructurePolicy($repository);
        $category = BalanceCategoryBuilder::aBalanceCategory()->build();
        $repository->save($category);

        $policy->assertCanSetParent($category, $category->getId(), $category->getCompanyId());
    }

    public function testThrowsOnDepthExceeded(): void
    {
        $this->expectException(BalanceDepthExceededException::class);

        $root = BalanceCategoryBuilder::aBalanceCategory()->withIndex(1)->build();
        $level2 = BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)->withParent($root)->build();
        $level3 = BalanceCategoryBuilder::aBalanceCategory()->withIndex(3)->withParent($level2)->build();
        $level4 = BalanceCategoryBuilder::aBalanceCategory()->withIndex(4)->withParent($level3)->build();
        $parent = BalanceCategoryBuilder::aBalanceCategory()->withIndex(5)->withParent($level4)->build();

        $repository = new InMemoryBalanceCategoryRepository();
        foreach ([$root, $level2, $level3, $level4, $parent] as $category) {
            $repository->save($category);
        }

        $policy = new BalanceStructurePolicy($repository);
        $category = BalanceCategoryBuilder::aBalanceCategory()->withIndex(6)->build();

        $policy->assertCanSetParent($category, $parent->getId(), $category->getCompanyId());
    }

    public function testThrowsWhenParentWouldCreateCycle(): void
    {
        $this->expectException(BalanceCategoryCycleException::class);

        $root = BalanceCategoryBuilder::aBalanceCategory()->withIndex(1)->build();
        $child = BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)->withParent($root)->build();
        $grandchild = BalanceCategoryBuilder::aBalanceCategory()->withIndex(3)->withParent($child)->build();

        $repository = new InMemoryBalanceCategoryRepository();
        foreach ([$root, $child, $grandchild] as $category) {
            $repository->save($category);
        }

        $policy = new BalanceStructurePolicy($repository);

        $policy->assertCanSetParent($root, $grandchild->getId(), $root->getCompanyId());
    }

    public function testAssertCodeIsUniqueThrowsOnDuplicate(): void
    {
        $this->expectException(\DomainException::class);

        $repository = new InMemoryBalanceCategoryRepository();
        $existing = BalanceCategoryBuilder::aBalanceCategory()->withCode('CASH')->build();
        $repository->save($existing);

        $policy = new BalanceStructurePolicy($repository);
        $policy->assertCodeIsUnique($existing->getCompanyId(), 'CASH');
    }

    public function testAssertCodeIsUniquePassesForEmptyCode(): void
    {
        $repository = new InMemoryBalanceCategoryRepository();
        $existing = BalanceCategoryBuilder::aBalanceCategory()->withCode('CASH')->build();
        $repository->save($existing);

        $policy = new BalanceStructurePolicy($repository);
        $policy->assertCodeIsUnique($existing->getCompanyId(), null);
        $policy->assertCodeIsUnique($existing->getCompanyId(), '');

        self::assertTrue(true);
    }
}
