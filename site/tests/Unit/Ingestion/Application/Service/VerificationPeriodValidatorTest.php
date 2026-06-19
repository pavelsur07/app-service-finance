<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Service;

use App\Ingestion\Application\Service\VerificationPeriodValidator;
use App\Ingestion\Exception\InvalidPeriodException;
use App\Ingestion\Exception\InvalidPeriodRangeException;
use PHPUnit\Framework\TestCase;

final class VerificationPeriodValidatorTest extends TestCase
{
    public function testParsesStrictDateRange(): void
    {
        $validator = new VerificationPeriodValidator();

        [$from, $to] = $validator->parseDateRange('2026-06-01', '2026-06-30');

        self::assertSame('2026-06-01', $from->format('Y-m-d'));
        self::assertSame('2026-06-30', $to->format('Y-m-d'));
    }

    public function testRejectsMalformedDate(): void
    {
        $this->expectException(InvalidPeriodException::class);

        (new VerificationPeriodValidator())->parseDate('2026-6-1');
    }

    public function testRejectsInvertedDateRange(): void
    {
        $this->expectException(InvalidPeriodRangeException::class);

        (new VerificationPeriodValidator())->parseDateRange('2026-06-30', '2026-06-01');
    }

    public function testAcceptsValidYearMonth(): void
    {
        (new VerificationPeriodValidator())->assertYearMonth(2026, 6);

        self::addToAssertionCount(1);
    }

    public function testRejectsInvalidYearMonth(): void
    {
        $this->expectException(InvalidPeriodException::class);

        (new VerificationPeriodValidator())->assertYearMonth(2019, 12);
    }

    public function testRejectsInvertedMonthRange(): void
    {
        $this->expectException(InvalidPeriodRangeException::class);

        (new VerificationPeriodValidator())->assertMonthRange(2026, 7, 2026, 6);
    }
}
