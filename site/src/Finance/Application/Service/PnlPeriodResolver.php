<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

final class PnlPeriodResolver
{
    private const REPORT_TIMEZONE = 'Europe/Moscow';

    /**
     * @return array{0: int, 1: int}
     */
    public function from(\DateTimeImmutable $at): array
    {
        $local = $at->setTimezone(new \DateTimeZone(self::REPORT_TIMEZONE));

        return [(int) $local->format('Y'), (int) $local->format('n')];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function bounds(int $year, int $month): array
    {
        $from = new \DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            new \DateTimeZone(self::REPORT_TIMEZONE),
        );

        return [$from, $from->modify('last day of this month')->setTime(23, 59, 59)];
    }
}
