<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Exception\InvalidPeriodException;
use App\Ingestion\Exception\InvalidPeriodRangeException;

final class VerificationPeriodValidator
{
    public function parseDate(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            false === $parsed
            || false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidPeriodException();
        }

        return $parsed;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function parseDateRange(string $from, string $to): array
    {
        $fromDate = $this->parseDate($from);
        $toDate = $this->parseDate($to);

        if ($fromDate > $toDate) {
            throw new InvalidPeriodRangeException();
        }

        return [$fromDate, $toDate];
    }

    public function assertYearMonth(int $year, int $month): void
    {
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidPeriodException();
        }
    }

    public function assertMonthRange(int $yearFrom, int $monthFrom, int $yearTo, int $monthTo): void
    {
        $this->assertYearMonth($yearFrom, $monthFrom);
        $this->assertYearMonth($yearTo, $monthTo);

        if (($yearFrom * 12 + $monthFrom) > ($yearTo * 12 + $monthTo)) {
            throw new InvalidPeriodRangeException();
        }
    }
}
