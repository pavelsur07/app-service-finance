<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\Domain\Service\AbcClassificationPolicy;
use App\MarketplaceAnalytics\Enum\AbcClass;
use PHPUnit\Framework\TestCase;

final class AbcClassificationPolicyTest extends TestCase
{
    private AbcClassificationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AbcClassificationPolicy();
    }

    public function testClassesByCumulativeShare(): void
    {
        // Доля до листинга: a 0%, b 50%, c 75%, d 85%, e 93%, f 96%, g 98%.
        $result = $this->policy->classify([
            'g' => 2.0,
            'a' => 50.0,
            'c' => 10.0,
            'b' => 25.0,
            'e' => 3.0,
            'd' => 8.0,
            'f' => 2.0,
        ], AbcClass::C);

        self::assertSame(AbcClass::A, $result['a']);
        self::assertSame(AbcClass::A, $result['b']);
        // c переходит 80% (75 → 85) — старший класс.
        self::assertSame(AbcClass::A, $result['c']);
        self::assertSame(AbcClass::B, $result['d']);
        // e переходит 95% (93 → 96) — старший класс.
        self::assertSame(AbcClass::B, $result['e']);
        self::assertSame(AbcClass::C, $result['f']);
        self::assertSame(AbcClass::C, $result['g']);
    }

    public function testListingStartingExactlyAtThresholdGoesToLowerClass(): void
    {
        // Доля до b — ровно 80%, до c — ровно 95%.
        $result = $this->policy->classify(['a' => 80.0, 'b' => 15.0, 'c' => 5.0], AbcClass::C);

        self::assertSame(AbcClass::A, $result['a']);
        self::assertSame(AbcClass::B, $result['b']);
        self::assertSame(AbcClass::C, $result['c']);
    }

    public function testExactThresholdWithKopecks(): void
    {
        // Доля до c — ровно 80% (0.4 + 0.4 из 1.00), до d — ровно 95%.
        $result = $this->policy->classify(['a' => 0.4, 'b' => 0.4, 'c' => 0.15, 'd' => 0.05], AbcClass::C);

        self::assertSame(AbcClass::B, $result['c']);
        self::assertSame(AbcClass::C, $result['d']);
    }

    public function testNonPositiveValuesGetGivenClassAndDoNotAffectShares(): void
    {
        $result = $this->policy->classify(['a' => 90.0, 'b' => 10.0, 'loss' => -500.0, 'zero' => 0.0], AbcClass::D);

        self::assertSame(AbcClass::A, $result['a']);
        // Без отрицательной суммы в базе: доля до b = 90% → B.
        self::assertSame(AbcClass::B, $result['b']);
        self::assertSame(AbcClass::D, $result['loss']);
        self::assertSame(AbcClass::D, $result['zero']);
    }

    public function testEqualValuesAreOrderedById(): void
    {
        $first = $this->policy->classify(['z' => 10.0, 'a' => 10.0], AbcClass::C);
        $second = $this->policy->classify(['a' => 10.0, 'z' => 10.0], AbcClass::C);

        // Порядок задаёт ID, а не вход.
        self::assertSame($first, $second);
        self::assertSame(['a', 'z'], array_keys($first));
    }

    public function testTieOrderDecidesClassAtBoundary(): void
    {
        // Доля до первого из равных — 90%: первый (по ID) B, второй — 95% → C.
        $result = $this->policy->classify(['big' => 90.0, 'y' => 5.0, 'x' => 5.0], AbcClass::C);

        self::assertSame(AbcClass::B, $result['x']);
        self::assertSame(AbcClass::C, $result['y']);
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], $this->policy->classify([], AbcClass::C));
    }

    public function testSingleListingIsA(): void
    {
        self::assertSame(['only' => AbcClass::A], $this->policy->classify(['only' => 1.0], AbcClass::C));
    }
}
