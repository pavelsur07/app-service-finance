<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\DTO\IsoWeek;

/**
 * ISO-недели (`YYYY-Www`, контракт service-coverage v1.1), пересекающие месяц.
 */
final readonly class IsoWeeks
{
    private const TIMEZONE = 'Europe/Moscow';

    /**
     * @param string $month YYYY-MM
     *
     * @return list<IsoWeek>
     */
    public static function ofMonth(string $month): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $first = \DateTimeImmutable::createFromFormat('!Y-m', $month, $tz);
        if (false === $first || $first->format('Y-m') !== $month) {
            throw new \InvalidArgumentException(sprintf('Invalid month "%s".', $month));
        }
        $last = $first->modify('last day of this month');

        $weeks = [];
        for ($monday = $first->modify('-'.((int) $first->format('N') - 1).' days'); $monday <= $last; $monday = $monday->modify('+7 days')) {
            $sunday = $monday->modify('+6 days');
            $from = max($monday, $first);
            $to = min($sunday, $last);
            $weeks[] = new IsoWeek(
                key: $monday->format('o-\WW'),
                monday: $monday,
                sunday: $sunday,
                daysInMonth: (int) $from->diff($to)->days + 1,
            );
        }

        return $weeks;
    }
}
