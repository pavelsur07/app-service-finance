<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\Clock\ClockInterface;

final class WbFinancialReportPeriodResolver
{
    private const BUSINESS_TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function yesterday(): DateTimeImmutable
    {
        return $this->nowInBusinessTimezone()->modify('-1 day');
    }

    public function currentYearStart(): DateTimeImmutable
    {
        $now = $this->nowInBusinessTimezone();

        return $now->setDate((int) $now->format('Y'), 1, 1);
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function last14Days(): array
    {
        $to = $this->yesterday();
        $from = $to->modify('-13 days');

        return $this->daysBetween($from, $to);
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $normalizedFrom = $this->normalizeToBusinessDayStart($from);
        $normalizedTo = $this->normalizeToBusinessDayStart($to);

        if ($normalizedFrom > $normalizedTo) {
            throw new DomainException('From date must be less than or equal to to date.');
        }

        $days = [];
        for ($cursor = $normalizedFrom; $cursor <= $normalizedTo; $cursor = $cursor->add(new DateInterval('P1D'))) {
            $days[] = $cursor;
        }

        return $days;
    }

    public function normalizeBusinessDate(string $date): DateTimeImmutable
    {
        $trimmed = trim($date);
        if ('' === $trimmed) {
            throw new InvalidArgumentException('Business date must not be empty.');
        }

        $timezone = $this->businessTimezone();
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (false === $parsed || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new DomainException(sprintf('Invalid WB business date: "%s".', $date));
        }

        return $parsed->setTime(0, 0, 0);
    }

    private function nowInBusinessTimezone(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->businessTimezone())->setTime(0, 0, 0);
    }

    private function normalizeToBusinessDayStart(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone($this->businessTimezone())->setTime(0, 0, 0);
    }

    private function businessTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::BUSINESS_TIMEZONE);
    }
}
