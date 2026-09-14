<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Entity;

use App\Balance\Exception\BalanceDepthExceededException;
use App\Tests\Builders\Balance\BalanceCategoryBuilder;
use PHPUnit\Framework\TestCase;

final class BalanceCategoryLedgerTest extends TestCase
{
    public function testFifthArticleLevelIsRejectedWithoutChangingParent(): void
    {
        $parent = null;
        for ($i = 1; $i <= 4; ++$i) {
            $parent = BalanceCategoryBuilder::aBalanceCategory()->withIndex($i)->withParent($parent)->build();
        }
        $child = BalanceCategoryBuilder::aBalanceCategory()->withIndex(5)->build();
        try {
            $child->setParent($parent);
            self::fail('A fifth article level must not be accepted.');
        } catch (BalanceDepthExceededException) {
            self::assertNull($child->getParent());
            self::assertSame(1, $child->getLevel());
        }
    }

    public function testParentFromAnotherCompanyIsRejected(): void
    {
        $parent = BalanceCategoryBuilder::aBalanceCategory()->withCompanyId('33333333-3333-4333-8333-333333333333')->build();
        $child = BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)->build();
        $this->expectException(\DomainException::class);
        $child->setParent($parent);
    }
}
