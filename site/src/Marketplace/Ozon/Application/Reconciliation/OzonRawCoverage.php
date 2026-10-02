<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation;

use App\Marketplace\Ozon\Application\Service\OzonAccrualSyncPlanner;

/**
 * Сколько дней периода должно быть загружено из by-day: от первого дня, который by-day вправе заводить,
 * до вчерашнего (по Москве, как у планировщика синка).
 */
final class OzonRawCoverage
{
    private const TIMEZONE = 'Europe/Moscow';

    /**
     * Покрывает ли загрузка по дням весь месяц целиком: by-day вправе заводить дни только с `EARLIEST_SAFE_DAY`,
     * раньше лежит снятый формат v3. Месячный отчёт «Реализация» сравним с сырьём by-day только если месяц начинается не раньше.
     */
    public static function coversWholeMonth(\DateTimeImmutable $from): bool
    {
        return $from->format('Y-m-d') >= OzonAccrualSyncPlanner::EARLIEST_SAFE_DAY;
    }

    public static function expectedDays(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): int
    {
        $first = max($from->format('Y-m-d'), OzonAccrualSyncPlanner::EARLIEST_SAFE_DAY);
        $yesterday = $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->modify('-1 day')->format('Y-m-d');
        $last = min($to->format('Y-m-d'), $yesterday);

        if ($first > $last) {
            return 0;
        }

        return (int) (new \DateTimeImmutable($first))->diff(new \DateTimeImmutable($last))->days + 1;
    }
}
