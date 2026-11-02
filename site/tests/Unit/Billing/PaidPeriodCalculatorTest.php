<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\PaidPeriodCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaidPeriodCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function firstPayment(): iterable
    {
        yield '01.01 15:00, 1 мес' => ['2027-01-01 15:00:00', 1, '2027-01-01', '2027-01-31'];
        yield 'середина месяца' => ['2027-01-15 09:00:00', 1, '2027-01-15', '2027-02-14'];
        yield '31.01 → последний день февраля' => ['2027-01-31 10:00:00', 1, '2027-01-31', '2027-02-28'];
        yield '29.01 невисокосный → 28.02' => ['2027-01-29 10:00:00', 1, '2027-01-29', '2027-02-28'];
        yield '28.01 → 27.02' => ['2027-01-28 10:00:00', 1, '2027-01-28', '2027-02-27'];
        yield '30.01 високосный → 29.02' => ['2028-01-30 10:00:00', 1, '2028-01-30', '2028-02-29'];
        yield '31.03 → 30.04' => ['2027-03-31 10:00:00', 1, '2027-03-31', '2027-04-30'];
        yield '3 мес' => ['2027-01-01 15:00:00', 3, '2027-01-01', '2027-03-31'];
        yield '12 мес через год' => ['2027-11-20 15:00:00', 12, '2027-11-20', '2028-11-19'];
        yield '6 мес через год из 31-го' => ['2027-08-31 15:00:00', 6, '2027-08-31', '2028-02-29'];
    }

    #[DataProvider('firstPayment')]
    public function testFirstPayment(string $receivedAt, int $months, string $from, string $until): void
    {
        $period = (new PaidPeriodCalculator())->next(null, new \DateTimeImmutable($receivedAt, new \DateTimeZone('Europe/Moscow')), $months);

        self::assertSame($from, $period->from->format('Y-m-d'));
        self::assertSame($until, $period->until->format('Y-m-d'));
    }

    public function testRenewalBeforeEndContinuesFromNextDayKeepingAnniversary(): void
    {
        $period = (new PaidPeriodCalculator())->next(new \DateTimeImmutable('2027-02-14'), new \DateTimeImmutable('2027-02-10 12:00:00'), 1);

        self::assertSame('2027-02-15', $period->from->format('Y-m-d'));
        self::assertSame('2027-03-14', $period->until->format('Y-m-d'));
    }

    public function testRenewalOnLastPaidDayContinues(): void
    {
        $period = (new PaidPeriodCalculator())->next(new \DateTimeImmutable('2027-02-14'), new \DateTimeImmutable('2027-02-14 23:00:00'), 1);

        self::assertSame('2027-02-15', $period->from->format('Y-m-d'));
    }

    public function testPaymentAfterEndStartsFromReceivedDay(): void
    {
        $period = (new PaidPeriodCalculator())->next(new \DateTimeImmutable('2027-02-14'), new \DateTimeImmutable('2027-02-20 12:00:00'), 1);

        self::assertSame('2027-02-20', $period->from->format('Y-m-d'));
        self::assertSame('2027-03-19', $period->until->format('Y-m-d'));
    }

    public function testReceivedDayIsTakenInMoscow(): void
    {
        // 31.01 21:30 UTC = 01.02 00:30 МСК
        $period = (new PaidPeriodCalculator())->next(null, new \DateTimeImmutable('2027-01-31 21:30:00', new \DateTimeZone('UTC')), 1);

        self::assertSame('2027-02-01', $period->from->format('Y-m-d'));
        self::assertSame('2027-02-28', $period->until->format('Y-m-d'));
    }

    public function testPaidUntilDateIsNotShiftedByItsTimezone(): void
    {
        // Значение колонки DATE в часовом поясе восточнее Москвы — дата та же, без перевода.
        $paidUntil = new \DateTimeImmutable('2027-02-14 00:00:00', new \DateTimeZone('Asia/Yekaterinburg'));
        $period = (new PaidPeriodCalculator())->next($paidUntil, new \DateTimeImmutable('2027-02-14 12:00:00', new \DateTimeZone('Europe/Moscow')), 1);

        self::assertSame('2027-02-15', $period->from->format('Y-m-d'));
    }

    public function testRejectsZeroMonths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PaidPeriodCalculator())->next(null, new \DateTimeImmutable('2027-01-01'), 0);
    }

    public function testTrialCountsStartDayInMoscow(): void
    {
        // 21:30 UTC 31.12 — уже 01.01 по Москве.
        $period = (new PaidPeriodCalculator())->trial(new \DateTimeImmutable('2026-12-31 21:30:00', new \DateTimeZone('UTC')), 14);

        self::assertSame('2027-01-01', $period->from->format('Y-m-d'));
        self::assertSame('2027-01-14', $period->until->format('Y-m-d'));
    }
}
