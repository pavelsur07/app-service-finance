<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

/**
 * Колонка план–факт. `months` — месяцы, по которым считаются план и факт колонки: у квартала с
 * незакрытыми месяцами и у «с начала года» — только закрытые (частичный факт с полным планом не
 * сравнивается). Состояние: closed — сравнивается; current — месяц идёт, факт без отклонения;
 * future — только план; partial — квартал, сравниваются закрытые месяцы.
 */
final readonly class BudgetPlanFactColumn
{
    public const string YTD = 'ytd';
    public const string YEAR = 'total';

    public const string KIND_MONTH = 'month';
    public const string KIND_QUARTER = 'quarter';
    public const string KIND_YTD = 'ytd';
    public const string KIND_YEAR = 'year';

    public const string CLOSED = 'closed';
    public const string CURRENT = 'current';
    public const string FUTURE = 'future';
    public const string PARTIAL = 'partial';

    /**
     * @param list<string> $months `Y-m`
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $state,
        public array $months,
    ) {
    }

    public function compared(): bool
    {
        return self::CLOSED === $this->state || self::PARTIAL === $this->state;
    }
}
