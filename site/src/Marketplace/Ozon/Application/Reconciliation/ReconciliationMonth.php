<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation;

/**
 * Месяц сверки: `YYYY-MM` ↔ границы периода.
 */
final readonly class ReconciliationMonth
{
    private const MONTHS = [1 => 'январь', 2 => 'февраль', 3 => 'март', 4 => 'апрель', 5 => 'май', 6 => 'июнь',
        7 => 'июль', 8 => 'август', 9 => 'сентябрь', 10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь'];

    private function __construct(public \DateTimeImmutable $from)
    {
    }

    public static function tryParse(mixed $raw): ?self
    {
        if (!is_string($raw) || 1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) {
            return null;
        }

        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw.'-01');

        return false === $first ? null : new self($first);
    }

    public static function containing(\DateTimeImmutable $date): self
    {
        return new self($date->modify('first day of this month')->setTime(0, 0));
    }

    public function to(): \DateTimeImmutable
    {
        return $this->from->modify('last day of this month');
    }

    public function value(): string
    {
        return $this->from->format('Y-m');
    }

    public function label(): string
    {
        return sprintf('%s %s', self::MONTHS[(int) $this->from->format('n')], $this->from->format('Y'));
    }
}
