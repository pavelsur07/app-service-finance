<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Domain\Reconciliation;

use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Ozon\Domain\Reconciliation\OzonReconciliationTolerance;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OzonReconciliationToleranceTest extends TestCase
{
    /**
     * @return iterable<string, array{?int, ?int, OzonReconciliationStatus}>
     */
    public static function cases(): iterable
    {
        yield 'равны' => [10000, 10000, OzonReconciliationStatus::MATCHED];
        yield 'нули' => [0, 0, OzonReconciliationStatus::MATCHED];
        yield 'цель больше ровно на 1 ₽' => [10000, 10100, OzonReconciliationStatus::WITHIN_TOLERANCE];
        yield 'цель меньше ровно на 1 ₽' => [10000, 9900, OzonReconciliationStatus::WITHIN_TOLERANCE];
        yield 'копейка' => [10000, 10001, OzonReconciliationStatus::WITHIN_TOLERANCE];
        yield 'больше допуска на копейку' => [10000, 10101, OzonReconciliationStatus::MISMATCH];
        yield 'меньше допуска на копейку' => [10000, 9899, OzonReconciliationStatus::MISMATCH];
        yield 'знак перепутан при равных модулях' => [10000, -10000, OzonReconciliationStatus::MISMATCH];
        yield 'отрицательные равны' => [-5000, -5000, OzonReconciliationStatus::MATCHED];
        yield 'нет источника' => [null, 10000, OzonReconciliationStatus::NO_DATA];
        yield 'нет цели' => [10000, null, OzonReconciliationStatus::NO_DATA];
        yield 'нет обеих сторон' => [null, null, OzonReconciliationStatus::NO_DATA];
    }

    #[DataProvider('cases')]
    public function testEvaluate(?int $source, ?int $target, OzonReconciliationStatus $expected): void
    {
        $tolerance = OzonReconciliationTolerance::oneRuble();

        self::assertSame($expected, $tolerance->evaluate($this->money($source), $this->money($target)));
    }

    public function testDeltaIsSignedTargetMinusSource(): void
    {
        $delta = OzonReconciliationTolerance::oneRuble()->delta($this->money(10000), $this->money(9500));

        self::assertNotNull($delta);
        self::assertSame(-500, $delta->amountMinor());
    }

    public function testDeltaWithoutSideIsNull(): void
    {
        self::assertNull(OzonReconciliationTolerance::oneRuble()->delta(null, $this->money(1)));
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OzonReconciliationTolerance::of(Money::fromMinor(-1, 'RUB'));
    }

    public function testCustomLimit(): void
    {
        $tolerance = OzonReconciliationTolerance::of(Money::fromMinor(0, 'RUB'));

        self::assertSame(OzonReconciliationStatus::MISMATCH, $tolerance->evaluate($this->money(100), $this->money(101)));
    }

    public function testWorstOfPrefersMismatchThenNoData(): void
    {
        self::assertSame(OzonReconciliationStatus::NO_DATA, OzonReconciliationStatus::worstOf([]));
        self::assertSame(OzonReconciliationStatus::MATCHED, OzonReconciliationStatus::worstOf([OzonReconciliationStatus::MATCHED]));
        self::assertSame(OzonReconciliationStatus::WITHIN_TOLERANCE, OzonReconciliationStatus::worstOf([
            OzonReconciliationStatus::MATCHED,
            OzonReconciliationStatus::WITHIN_TOLERANCE,
        ]));
        self::assertSame(OzonReconciliationStatus::NO_DATA, OzonReconciliationStatus::worstOf([
            OzonReconciliationStatus::WITHIN_TOLERANCE,
            OzonReconciliationStatus::NO_DATA,
        ]));
        self::assertSame(OzonReconciliationStatus::MISMATCH, OzonReconciliationStatus::worstOf([
            OzonReconciliationStatus::NO_DATA,
            OzonReconciliationStatus::MISMATCH,
            OzonReconciliationStatus::MATCHED,
        ]));
    }

    private function money(?int $minor): ?Money
    {
        return null === $minor ? null : Money::fromMinor($minor, 'RUB');
    }
}
