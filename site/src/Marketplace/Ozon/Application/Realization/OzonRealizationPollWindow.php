<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Realization;

/**
 * Окно опроса готовности отчёта «Реализация»: с 18:00 МСК 1-го числа до конца 8-го числа.
 * Отчётный месяц — предыдущий. Ozon формирует отчёт не ранее 5–8 числа, но вечером 1-го уже имеет смысл спрашивать.
 * Окно живёт в коде, а не в cron: границы проверяются тестами, расписание остаётся «каждый час».
 */
final readonly class OzonRealizationPollWindow
{
    public const TIMEZONE = 'Europe/Moscow';
    public const OPENS_AT_HOUR = 18;
    public const LAST_DAY = 8;

    private function __construct(
        public int $reportYear,
        public int $reportMonth,
    ) {
    }

    /**
     * Открытое окно на момент `$now` либо `null`, если сейчас вне окна.
     */
    public static function at(\DateTimeImmutable $now): ?self
    {
        $local = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $day = (int) $local->format('j');

        // 1-е — только вечером; со 2-го по 8-е — круглые сутки.
        $open = 1 === $day ? (int) $local->format('G') >= self::OPENS_AT_HOUR : $day <= self::LAST_DAY;

        if (!$open) {
            return null;
        }

        $reportMonth = $local->modify('first day of last month');

        return new self((int) $reportMonth->format('Y'), (int) $reportMonth->format('n'));
    }

    public function businessDate(): \DateTimeImmutable
    {
        return OzonRealizationReport::businessDate($this->reportYear, $this->reportMonth);
    }
}
