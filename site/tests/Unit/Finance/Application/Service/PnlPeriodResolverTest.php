<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application\Service;

use App\Finance\Application\Service\PnlPeriodResolver;
use PHPUnit\Framework\TestCase;

final class PnlPeriodResolverTest extends TestCase
{
    public function testResolvesPeriodInMoscowTimezone(): void
    {
        $resolver = new PnlPeriodResolver();

        self::assertSame([2026, 1], $resolver->from(new \DateTimeImmutable('2025-12-31 21:30:00 UTC')));
        self::assertSame([2026, 2], $resolver->from(new \DateTimeImmutable('2026-02-28 20:30:00 UTC')));
    }

    public function testBuildsMoscowMonthBounds(): void
    {
        $resolver = new PnlPeriodResolver();

        [$from, $to] = $resolver->bounds(2026, 2);

        self::assertSame('2026-02-01 00:00:00 +03:00', $from->format('Y-m-d H:i:s P'));
        self::assertSame('2026-02-28 23:59:59 +03:00', $to->format('Y-m-d H:i:s P'));
    }
}
