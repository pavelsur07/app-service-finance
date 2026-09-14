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
        $parent = BalanceCategoryBuilder::aBalanceCategory()->withIndex(4)->withParent($level3)->build();

        $repository = new InMemoryBalanceCategoryRepository();
        foreach ([$root, $level2, $level3, $parent] as $category) {
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

    public function testMovingSubtreeCannotPushDescendantsBeyondFourLevels(): void
    {
        $root = BalanceCategoryBuilder::aBalanceCategory()->withIndex(1)->build();
        $child = BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)->withParent($root)->build();
        $targetRoot = BalanceCategoryBuilder::aBalanceCategory()->withIndex(3)->build();
        $target2 = BalanceCategoryBuilder::aBalanceCategory()->withIndex(4)->withParent($targetRoot)->build();
        $target3 = BalanceCategoryBuilder::aBalanceCategory()->withIndex(5)->withParent($target2)->build();
        $repository = new InMemoryBalanceCategoryRepository();
        foreach ([$root, $child, $targetRoot, $target2, $target3] as $category) {
            $repository->save($category);
        }
        $policy = new BalanceStructurePolicy($repository);
        $this->expectException(BalanceDepthExceededException::class);
        $policy->assertCanSetParent($root, $target3->getId(), $root->getCompanyId());
    }

    public function testArchivedDescendantsDoNotPreventRenamingOrMovingTheirActiveAncestor(): void
    {
        $root = BalanceCategoryBuilder::aBalanceCategory()->withIndex(1)->build();
        $group = BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)->withParent($root)->build();
        $leaf = BalanceCategoryBuilder::aBalanceCategory()->withIndex(3)->withParent($group)->build();
        $target = BalanceCategoryBuilder::aBalanceCategory()->withIndex(4)->build();
        $leaf->setIsArchived(true);
        $group->setIsArchived(true);
        $repository = new InMemoryBalanceCategoryRepository();
        foreach ([$root, $group, $leaf, $target] as $category) {
            $repository->save($category);
        }
        $policy = new BalanceStructurePolicy($repository);
        $policy->assertCanSetParent($root, null, $root->getCompanyId());
        self::assertSame(3, $leaf->getLevel());
        $policy->assertCanSetParent($root, $target->getId(), $root->getCompanyId());
        self::assertSame(4, $leaf->getLevel());
        self::assertTrue($group->isArchived());
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
