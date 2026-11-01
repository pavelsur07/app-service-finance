<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Webmozart\Assert\Assert;

/**
 * Оплаченный период: календарные даты «с» и «по», обе включительно, без времени.
 * Принимает даты, а не моменты: берётся их Y-m-d без перевода часового пояса.
 */
final readonly class PaidPeriod
{
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $until;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $until)
    {
        $this->from = PaidPeriodCalculator::date($from);
        $this->until = PaidPeriodCalculator::date($until);
        Assert::true($this->from <= $this->until, 'Начало оплаченного периода позже его окончания.');
    }
}
