<?php

declare(strict_types=1);

namespace App\Budget\Domain;

/**
 * Плановая сумма — строка NUMERIC(18,2) в знаке значения отчёта ОПиУ (доход − расход).
 */
final class BudgetAmount
{
    private const PATTERN = '/^-?\d{1,16}(\.\d{1,2})?$/';

    public static function isValid(string $amount): bool
    {
        return 1 === preg_match(self::PATTERN, $amount);
    }

    public static function isZero(string $amount): bool
    {
        return 1 === preg_match('/^-?0+(\.0+)?$/', $amount);
    }
}
