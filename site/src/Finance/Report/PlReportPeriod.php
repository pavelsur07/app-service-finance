<?php

declare(strict_types=1);

namespace App\Finance\Report;

final class PlReportPeriod
{
    public readonly \DateTimeImmutable $from;
    public readonly \DateTimeImmutable $to;
    public readonly string $id;
    public readonly string $label;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to, string $label)
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('`from` date must be earlier than `to` date');
        }

        $this->from = $from;
        $this->to = $to;
        $this->label = $label;
        $this->id = sprintf('%s_%s', $from->format('Ymd'), $to->format('Ymd'));
    }

    public static function forRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $label = null): self
    {
        $normalizedFrom = $from->setTime(0, 0, 0);
        $normalizedTo = $to->setTime(23, 59, 59);

        if (null === $label) {
            if ($normalizedFrom->format('Ymd') === $normalizedTo->format('Ymd')) {
                $label = $normalizedFrom->format('d.m.Y');
            } else {
                $label = sprintf('%s — %s', $normalizedFrom->format('d.m.Y'), $normalizedTo->format('d.m.Y'));
            }
        }

        return new self($normalizedFrom, $normalizedTo, $label);
    }

    public static function forMonth(\DateTimeImmutable $anyDayOfMonth): self
    {
        $start = $anyDayOfMonth->modify('first day of this month')->setTime(0, 0, 0);
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);

        return new self($start, $end, $start->format('Y-m'));
    }
}
