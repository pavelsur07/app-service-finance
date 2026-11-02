<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use Webmozart\Assert\Assert;

/**
 * Период «от даты оплаты».
 *
 * - День зачисления — календарная дата момента зачисления по Москве; засчитывается целиком.
 * - Оплата до окончания текущего периода продлевает его со следующего дня (день продления
 *   сохраняется); после окончания — новый период с дня зачисления.
 * - Конец = начало + N месяцев − 1 день; если числа начала нет в целевом месяце —
 *   последний день целевого месяца (31.01 + 1 мес → 28.02).
 */
final readonly class PaidPeriodCalculator
{
    public const TIMEZONE = 'Europe/Moscow';

    /**
     * @param \DateTimeImmutable|null $currentPaidUntil «оплачено по» текущей подписки, если её надо продолжить
     */
    public function next(?\DateTimeImmutable $currentPaidUntil, \DateTimeImmutable $receivedAt, int $months): PaidPeriod
    {
        Assert::greaterThanEq($months, 1, 'Срок оплаты — не меньше месяца.');

        $receivedDay = self::day($receivedAt);
        $from = null !== $currentPaidUntil && self::date($currentPaidUntil) >= $receivedDay
            ? self::date($currentPaidUntil)->modify('+1 day')
            : $receivedDay;

        return new PaidPeriod($from, self::untilFor($from, $months));
    }

    /**
     * Пробный период: $days дней, включая день начала (по Москве).
     */
    public function trial(\DateTimeImmutable $startedAt, int $days): PaidPeriod
    {
        Assert::greaterThanEq($days, 1, 'Пробный период — не меньше дня.');
        $from = self::day($startedAt);

        return new PaidPeriod($from, $from->modify('+'.($days - 1).' days'));
    }

    /**
     * Календарная дата момента по Москве, полночь в часовом поясе Москвы.
     */
    public static function day(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        $zone = new \DateTimeZone(self::TIMEZONE);

        return new \DateTimeImmutable($moment->setTimezone($zone)->format('Y-m-d'), $zone);
    }

    /**
     * Календарная дата без перевода часового пояса (значение колонки DATE): та же Y-m-d,
     * полночь по Москве.
     */
    public static function date(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone(self::TIMEZONE));
    }

    private static function untilFor(\DateTimeImmutable $from, int $months): \DateTimeImmutable
    {
        $day = (int) $from->format('j');
        $monthIndex = (int) $from->format('Y') * 12 + (int) $from->format('n') - 1 + $months;
        $targetFirst = $from->setDate(intdiv($monthIndex, 12), $monthIndex % 12 + 1, 1);
        $daysInTarget = (int) $targetFirst->format('t');

        if ($day > $daysInTarget) {
            return $targetFirst->setDate((int) $targetFirst->format('Y'), (int) $targetFirst->format('n'), $daysInTarget);
        }

        return $targetFirst->setDate((int) $targetFirst->format('Y'), (int) $targetFirst->format('n'), $day)->modify('-1 day');
    }
}
