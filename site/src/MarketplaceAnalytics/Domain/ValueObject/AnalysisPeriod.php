<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\ValueObject;

use Webmozart\Assert\Assert;

final readonly class AnalysisPeriod
{
    public function __construct(
        public readonly \DateTimeImmutable $dateFrom,
        public readonly \DateTimeImmutable $dateTo,
    ) {
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('dateFrom не может быть позже dateTo');
        }

        $today = new \DateTimeImmutable('today');
        if ($dateTo > $today) {
            throw new \InvalidArgumentException('dateTo не может быть в будущем');
        }
    }

    public function lengthInDays(): int
    {
        return (int) $this->dateFrom->diff($this->dateTo)->days + 1;
    }

    public function previousPeriod(): self
    {
        $days = $this->lengthInDays();
        $newDateTo = $this->dateFrom->modify('-1 day');
        $newDateFrom = $newDateTo->modify(sprintf('-%d days', $days - 1));

        return new self($newDateFrom, $newDateTo);
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        return $date >= $this->dateFrom && $date <= $this->dateTo;
    }

    public static function slidingWindow(int $days): self
    {
        Assert::greaterThan($days, 0);
        $dateTo = new \DateTimeImmutable('yesterday');
        $dateFrom = $dateTo->modify(sprintf('-%d days', $days - 1));

        return new self($dateFrom, $dateTo);
    }

    public static function currentWeek(): self
    {
        $dateTo = new \DateTimeImmutable('yesterday');
        $dateFrom = new \DateTimeImmutable('monday this week');
        if ($dateFrom > $dateTo) {
            $dateFrom = $dateTo;
        }

        return new self($dateFrom, $dateTo);
    }

    public static function currentMonth(): self
    {
        $dateTo = new \DateTimeImmutable('yesterday');
        $dateFrom = new \DateTimeImmutable('first day of this month');
        if ($dateFrom > $dateTo) {
            $dateFrom = $dateTo;
        }

        return new self($dateFrom, $dateTo);
    }

    public static function currentQuarter(): self
    {
        $now = new \DateTimeImmutable();
        $month = (int) $now->format('n');
        $quarter = (int) ceil($month / 3);
        $firstMonth = ($quarter - 1) * 3 + 1;
        $dateFrom = new \DateTimeImmutable(
            sprintf('%s-%02d-01', $now->format('Y'), $firstMonth)
        );
        $dateTo = new \DateTimeImmutable('yesterday');
        if ($dateFrom > $dateTo) {
            $dateFrom = $dateTo;
        }

        return new self($dateFrom, $dateTo);
    }

    public static function custom(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        return new self($from, $to);
    }
}
