<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Realization;

use App\Marketplace\Ozon\Application\Realization\OzonRealizationPollWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OzonRealizationPollWindowTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}> now (с часовым поясом) → отчётный месяц YYYY-MM либо null
     */
    public static function moments(): iterable
    {
        yield '1-е 17:59 МСК — рано' => ['2026-10-01 17:59:59 Europe/Moscow', null];
        yield '1-е 18:00 МСК — открыто' => ['2026-10-01 18:00:00 Europe/Moscow', '2026-09'];
        yield '1-е 23:59 МСК' => ['2026-10-01 23:59:59 Europe/Moscow', '2026-09'];
        yield '2-е ночью' => ['2026-10-02 00:00:00 Europe/Moscow', '2026-09'];
        yield '5-е' => ['2026-10-05 06:00:00 Europe/Moscow', '2026-09'];
        yield '8-е 23:59:59 — последняя секунда' => ['2026-10-08 23:59:59 Europe/Moscow', '2026-09'];
        yield '9-е 00:00 — закрыто' => ['2026-10-09 00:00:00 Europe/Moscow', null];
        yield 'середина месяца' => ['2026-10-15 12:00:00 Europe/Moscow', null];
        yield 'конец месяца' => ['2026-10-31 23:59:59 Europe/Moscow', null];
        yield 'январь → отчёт за декабрь' => ['2027-01-03 10:00:00 Europe/Moscow', '2026-12'];
        yield 'март после високосного февраля' => ['2028-03-02 10:00:00 Europe/Moscow', '2028-02'];
        // 15:00 UTC 1-го = 18:00 МСК: окно считается по московскому времени, а не по UTC.
        yield 'UTC 14:59 1-го — ещё рано по МСК' => ['2026-10-01 14:59:59 UTC', null];
        yield 'UTC 15:00 1-го — 18:00 МСК' => ['2026-10-01 15:00:00 UTC', '2026-09'];
        // 21:30 UTC 8-го = 00:30 МСК 9-го.
        yield 'UTC 8-го 21:30 — уже 9-е по МСК' => ['2026-10-08 21:30:00 UTC', null];
        yield 'UTC 8-го 20:59 — ещё 8-е по МСК' => ['2026-10-08 20:59:59 UTC', '2026-09'];
    }

    #[DataProvider('moments')]
    public function testWindow(string $now, ?string $expectedReportMonth): void
    {
        $window = OzonRealizationPollWindow::at(new \DateTimeImmutable($now));

        if (null === $expectedReportMonth) {
            self::assertNull($window);

            return;
        }

        self::assertNotNull($window);
        self::assertSame($expectedReportMonth, sprintf('%04d-%02d', $window->reportYear, $window->reportMonth));
        self::assertSame($expectedReportMonth.'-01', $window->businessDate()->format('Y-m-d'));
    }

    public function testWindowEndsAtTheEndOfTheEighthDayMoscow(): void
    {
        $window = OzonRealizationPollWindow::at(new \DateTimeImmutable('2026-10-03 10:00:00 Europe/Moscow'));

        self::assertNotNull($window);
        self::assertSame('2026-10-08 23:59:59', $window->closesAt->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Moscow', $window->closesAt->getTimezone()->getName());
    }
}
