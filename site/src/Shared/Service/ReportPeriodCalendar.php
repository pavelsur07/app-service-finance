<?php

declare(strict_types=1);

namespace App\Shared\Service;

final class ReportPeriodCalendar
{
    public static function startOf(\DateTimeImmutable $date, string $group): \DateTimeImmutable
    {
        return match ($group) {
            'week' => $date->modify('monday this week')->setTime(0, 0),
            'quarter' => new \DateTimeImmutable(sprintf('%s-%02d-01', $date->format('Y'), 1 + 3 * intdiv((int) $date->format('n') - 1, 3))),
            'year' => new \DateTimeImmutable($date->format('Y-01-01')),
            'month' => new \DateTimeImmutable($date->format('Y-m-01')),
            default => $date->setTime(0, 0),
        };
    }

    /**
     * Недельные колонки идут от переданной начальной даты.
     *
     * @return list<array{label: string, start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public static function periods(\DateTimeImmutable $from, \DateTimeImmutable $to, string $group): array
    {
        $periods = [];
        $current = $from;
        while ($current <= $to) {
            switch ($group) {
                case 'day':
                    $start = $current->setTime(0, 0);
                    $end = $current->setTime(23, 59, 59);
                    $label = $current->format('d.m.Y');
                    $current = $current->modify('+1 day');
                    break;
                case 'week':
                    $start = $current->setTime(0, 0);
                    $end = min($start->modify('+6 days')->setTime(23, 59, 59), $to->setTime(23, 59, 59));
                    $label = $start->format('d.m').'-'.$end->format('d.m');
                    $current = $end->modify('+1 day')->setTime(0, 0);
                    break;
                case 'quarter':
                    $start = self::startOf($current, 'quarter');
                    $end = min($start->modify('+3 months -1 day')->setTime(23, 59, 59), $to->setTime(23, 59, 59));
                    $label = 'Q'.(intdiv((int) $start->format('n') - 1, 3) + 1).' '.$start->format('Y');
                    $current = $end->modify('+1 day')->setTime(0, 0);
                    break;
                case 'year':
                    $start = self::startOf($current, 'year');
                    $end = min($start->modify('+1 year -1 day')->setTime(23, 59, 59), $to->setTime(23, 59, 59));
                    $label = $start->format('Y');
                    $current = $end->modify('+1 day')->setTime(0, 0);
                    break;
                case 'month':
                default:
                    $start = self::startOf($current, 'month');
                    $end = min($start->modify('+1 month -1 day')->setTime(23, 59, 59), $to->setTime(23, 59, 59));
                    $label = $start->format('m.Y');
                    $current = $end->modify('+1 day')->setTime(0, 0);
                    break;
            }
            $periods[] = ['label' => $label, 'start' => $start, 'end' => $end];
        }

        return $periods;
    }
}
