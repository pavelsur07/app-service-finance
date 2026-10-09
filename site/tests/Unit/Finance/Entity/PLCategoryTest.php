<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Entity;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\PLCategory;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PLCategoryTest extends TestCase
{
    public function testSetParentMaintainsChildrenInverseSide(): void
    {
        $company = $this->company();
        $root = new PLCategory(Uuid::uuid4()->toString(), $company);
        $leaf = new PLCategory(Uuid::uuid4()->toString(), $company);

        $leaf->setParent($root);

        // Without maintaining the inverse side, report SUBTOTAL rollups that
        // iterate getChildren() in memory silently see zero children.
        self::assertCount(1, $root->getChildren());
        self::assertTrue($root->getChildren()->contains($leaf));
        self::assertSame($root, $leaf->getParent());
    }

    public function testSetParentIsIdempotent(): void
    {
        $company = $this->company();
        $root = new PLCategory(Uuid::uuid4()->toString(), $company);
        $leaf = new PLCategory(Uuid::uuid4()->toString(), $company);

        $leaf->setParent($root);
        $leaf->setParent($root);

        self::assertCount(1, $root->getChildren());
    }

    public function testReparentingMovesChildBetweenParents(): void
    {
        $company = $this->company();
        $rootA = new PLCategory(Uuid::uuid4()->toString(), $company);
        $rootB = new PLCategory(Uuid::uuid4()->toString(), $company);
        $leaf = new PLCategory(Uuid::uuid4()->toString(), $company);

        $leaf->setParent($rootA);
        $leaf->setParent($rootB);

        self::assertCount(0, $rootA->getChildren());
        self::assertCount(1, $rootB->getChildren());
        self::assertTrue($rootB->getChildren()->contains($leaf));
    }

    public function testSetParentNullDetachesFromOldParent(): void
    {
        $company = $this->company();
        $root = new PLCategory(Uuid::uuid4()->toString(), $company);
        $leaf = new PLCategory(Uuid::uuid4()->toString(), $company);

        $leaf->setParent($root);
        $leaf->setParent(null);

        self::assertCount(0, $root->getChildren());
        self::assertNull($leaf->getParent());
    }

    public function testIsDescendantOf(): void
    {
        $company = $this->company();
        $root = new PLCategory(Uuid::uuid4()->toString(), $company);
        $child = new PLCategory(Uuid::uuid4()->toString(), $company);
        $grandchild = new PLCategory(Uuid::uuid4()->toString(), $company);
        $sibling = new PLCategory(Uuid::uuid4()->toString(), $company);

        $child->setParent($root);
        $grandchild->setParent($child);
        $sibling->setParent($root);

        self::assertTrue($child->isDescendantOf($root));
        self::assertTrue($grandchild->isDescendantOf($root));
        self::assertFalse($root->isDescendantOf($root));
        self::assertFalse($sibling->isDescendantOf($child));
    }

    /**
     * Прод-сценарий ИП Лазарева: группу вынесли в корень, её статьи остались на
     * старом уровне, и отчёт ОПиУ показал соседнюю статью как группу.
     */
    public function testMovingGroupToRootRecalculatesDescendantLevels(): void
    {
        [$root, $group, $leaf, $grandchild] = $this->chain(4);

        $group->setParent(null);

        self::assertSame(1, $root->getLevel());
        self::assertSame(1, $group->getLevel());
        self::assertSame(2, $leaf->getLevel());
        self::assertSame(3, $grandchild->getLevel());
    }

    public function testMovingGroupDeeperRecalculatesDescendantLevels(): void
    {
        [, $group, $leaf] = $this->chain(3);
        $newParent = $this->chain(2)[1];

        $group->setParent($newParent);

        self::assertSame(3, $group->getLevel());
        self::assertSame(4, $leaf->getLevel());
    }

    /**
     * @return list<PLCategory> цепочка от корня вниз, у элемента N уровень N+1
     */
    private function chain(int $length): array
    {
        $company = $this->company();
        $chain = [];
        $parent = null;
        for ($i = 0; $i < $length; ++$i) {
            $category = new PLCategory(Uuid::uuid4()->toString(), $company);
            $category->setParent($parent);
            $chain[] = $category;
            $parent = $category;
        }

        return $chain;
    }

    private function company(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('plcategory@example.com');
        $user->setPassword('password');

        return new Company(Uuid::uuid4()->toString(), $user);
    }
}
