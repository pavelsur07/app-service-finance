<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Realization;

use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OzonRealizationHeaderTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,bool}> */
    public static function headers(): iterable
    {
        yield 'date only' => [['start_date' => '2026-01-01', 'stop_date' => '2026-01-31'], true];
        yield 'ISO date-time' => [['start_date' => '2026-01-01T00:00:00Z', 'stop_date' => '2026-01-31T23:59:59+00:00'], true];
        yield 'preserve source calendar date' => [['start_date' => '2026-01-01T00:00:00+03:00', 'stop_date' => '2026-01-31T23:59:59.123456+03:00'], true];
        yield 'missing' => [[], false];
        yield 'wrong month' => [['start_date' => '2026-01-01', 'stop_date' => '2026-02-01T00:00:00Z'], false];
        yield 'rollover invalid date' => [['start_date' => '2025-12-32', 'stop_date' => '2026-01-31'], false];
        yield 'relative expression' => [['start_date' => '2025-12-31 +1 day', 'stop_date' => '2026-01-31'], false];
        yield 'invalid type' => [['start_date' => 0, 'stop_date' => '2026-01-31'], false];
    }

    /** @param array<string,mixed> $header */
    #[DataProvider('headers')]
    public function testHeaderUsesValidSourceCalendarDates(array $header, bool $expected): void
    {
        self::assertSame($expected, OzonRealizationReport::hasMatchingHeader(['result' => ['header' => $header]], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
    }
}
