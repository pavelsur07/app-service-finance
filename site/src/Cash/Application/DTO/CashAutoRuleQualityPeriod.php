<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class CashAutoRuleQualityPeriod
{
    private function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
    }

    public static function fromStrings(string $from, string $to): self
    {
        $today = new \DateTimeImmutable('today');
        $start = self::date($from, $today->modify('first day of this month'));
        $end = self::date($to, $today);
        if ($start > $end) {
            throw new UnprocessableEntityHttpException('Начальная дата периода позже конечной.');
        }

        return new self($start, $end);
    }

    private static function date(string $value, \DateTimeImmutable $default): \DateTimeImmutable
    {
        if ('' === $value) {
            return $default;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $value !== $date->format('Y-m-d')) {
            throw new UnprocessableEntityHttpException('Укажите дату в формате ГГГГ-ММ-ДД.');
        }

        return $date;
    }
}
