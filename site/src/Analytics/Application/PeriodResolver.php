<?php

namespace App\Analytics\Application;

use App\Analytics\Api\Request\SnapshotQuery;
use App\Analytics\Domain\Period;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PeriodResolver
{
    private const PRESETS = ['day', 'week', 'month'];
    private const MAX_PERIOD_DAYS = 366;

    private DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function resolve(SnapshotQuery $query, DateTimeImmutable $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))): Period
    {
        $now = $now->setTimezone($this->utc);
        $preset = $query->preset;
        $from = $query->from;
        $to = $query->to;

        if ($preset !== null && ($from !== null || $to !== null)) {
            throw new InvalidArgumentException('preset and from/to cannot be used together.');
        }

        if ($preset !== null) {
            return $this->resolvePreset($preset, $now);
        }

        if ($from === null && $to === null) {
            return $this->resolvePreset('month', $now);
        }

        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Both from and to are required for custom period.');
        }

        $period = new Period($this->parseDate($from, 'from'), $this->parseDate($to, 'to'));
        if ($period->days() > self::MAX_PERIOD_DAYS) {
            throw new InvalidArgumentException(sprintf('Period is too large. Maximum allowed span is %d days.', self::MAX_PERIOD_DAYS));
        }

        return $period;
    }

    private function resolvePreset(string $preset, DateTimeImmutable $now): Period
    {
        if (!in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported preset "%s".', $preset));
        }

        $today = $now->setTime(0, 0);

        return match ($preset) {
            'day' => new Period($today, $today),
            // Week is interpreted as current calendar week in UTC: Monday..Sunday.
            'week' => new Period($today->modify('monday this week'), $today->modify('sunday this week')),
            'month' => new Period($today->modify('first day of this month'), $today->modify('last day of this month')),
        };
    }

    private function parseDate(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->utc);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

        if ($date === false || $hasErrors) {
            throw new InvalidArgumentException(sprintf('Invalid %s date format, expected YYYY-MM-DD.', $field));
        }

        return $date;
    }
}
