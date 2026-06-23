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

    private function company(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('plcategory@example.com');
        $user->setPassword('password');

        return new Company(Uuid::uuid4()->toString(), $user);
    }
}
