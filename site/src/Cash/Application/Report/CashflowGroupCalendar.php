<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Shared\Service\ReportPeriodCalendar;

final readonly class CashflowGroupCalendar
{
    private const MAX_COLUMNS = 36;

    /** @param list<array{label:string, start:\DateTimeImmutable, end:\DateTimeImmutable}> $periods */
    private function __construct(
        public string $group,
        public string $requestedGroup,
        public array $periods,
        public bool $tooLarge,
    ) {
    }

    public static function forRange(\DateTimeImmutable $from, \DateTimeImmutable $to, string $group): self
    {
        // Поля occurred_at в отчёте — календарные даты. Сравниваем их в одной зоне.
        $from = new \DateTimeImmutable($from->format('Y-m-d'));
        $to = (new \DateTimeImmutable($to->format('Y-m-d')))->setTime(23, 59, 59);
        $requestedGroup = \in_array($group, ['week', 'month', 'quarter', 'year'], true) ? $group : 'month';
        $group = $requestedGroup;
        if ('week' === $group && ReportPeriodCalendar::startOf($from, 'week')->modify('+'.self::MAX_COLUMNS.' weeks') <= $to) {
            $group = 'month';
        }
        if ('month' === $group && ReportPeriodCalendar::startOf($from, 'month')->modify('+'.self::MAX_COLUMNS.' months') <= $to) {
            $group = 'quarter';
        }

        if ('quarter' === $group && ReportPeriodCalendar::startOf($from, 'quarter')->modify('+'.(3 * self::MAX_COLUMNS).' months') <= $to) {
            $group = 'year';
        }
        $start = ReportPeriodCalendar::startOf($from, $group);
        if ('year' === $group && $start->modify('+'.self::MAX_COLUMNS.' years') <= $to) {
            return new self($group, $requestedGroup, [], true);
        }

        $periods = [];
        foreach (ReportPeriodCalendar::periods($start, $to, $group) as $period) {
            $period['start'] = max($from, $period['start']);
            if ('week' === $group) {
                $period['label'] = $period['start']->format('d.m').'-'.$period['end']->format('d.m');
            } elseif ('quarter' === $group) {
                $period['label'] = (1 + intdiv((int) $period['start']->format('n') - 1, 3)).' кв. '.$period['start']->format('Y');
            }
            $periods[] = $period;
        }

        return new self($group, $requestedGroup, $periods, false);
    }

    /** Начало календарного интервала для сопоставления со строкой PostgreSQL date_trunc. */
    public function bucketKey(\DateTimeInterface $date): string
    {
        return ReportPeriodCalendar::startOf(\DateTimeImmutable::createFromInterface($date), $this->group)->format('Y-m-d');
    }
}
