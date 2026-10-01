<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Reconciliation;

use App\Marketplace\Ozon\Application\Reconciliation\ReconciliationMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReconciliationMonthTest extends TestCase
{
    public function testParsesValidMonth(): void
    {
        $month = ReconciliationMonth::tryParse('2026-02');

        self::assertNotNull($month);
        self::assertSame('2026-02-01', $month->from->format('Y-m-d'));
        self::assertSame('2026-02-28', $month->to()->format('Y-m-d'));
        self::assertSame('февраль 2026', $month->label());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'null' => [null];
        yield 'array' => [['2026-02']];
        yield 'пусто' => [''];
        yield 'месяц 13' => ['2026-13'];
        yield 'месяц 00' => ['2026-00'];
        yield 'с днём' => ['2026-02-01'];
        yield 'мусор' => ['abc'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(mixed $raw): void
    {
        self::assertNull(ReconciliationMonth::tryParse($raw));
    }

    public function testContainingNormalizesToFirstDay(): void
    {
        self::assertSame('2026-09', ReconciliationMonth::containing(new \DateTimeImmutable('2026-09-17 13:00'))->value());
    }
}
