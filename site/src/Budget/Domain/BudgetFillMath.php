<?php

declare(strict_types=1);

namespace App\Budget\Domain;

use App\Finance\Enum\PLFlow;

/**
 * Арифметика помощников заполнения плана: суммы — строки с двумя знаками, округление половины
 * от нуля. Знак — в знаке отчёта ОПиУ (расход с минусом).
 */
final class BudgetFillMath
{
    public const string MAX_PERCENT = '1000';

    /**
     * Поровну на `parts` частей, отбрасывая копейки; остаток — в последнюю (декабрь).
     *
     * @return list<string>
     */
    public static function distribute(string $amount, int $parts): array
    {
        $part = bcdiv($amount, (string) $parts, 2);
        $result = [];
        for ($i = 1; $i < $parts; ++$i) {
            $result[] = $part;
        }
        $result[] = bcsub($amount, bcmul($part, (string) ($parts - 1), 2), 2);

        return $result;
    }

    /**
     * Среднее за `months` месяцев: недостающие месяцы (без факта) — нули.
     *
     * @param list<string> $amounts
     */
    public static function average(array $amounts, int $months): string
    {
        $sum = array_reduce($amounts, static fn (string $carry, string $amount): string => bcadd($carry, $amount, 2), '0');

        return self::round(bcdiv($sum, (string) $months, 6));
    }

    public static function percentOf(string $base, string $percent): string
    {
        return self::round(bcdiv(bcmul($base, $percent, 6), '100', 6));
    }

    /**
     * Сумма, введённая пользователем, → знак отчёта по правилу ввода (решение Владельца 04.11, как
     * в сетке): в статье расхода число меняет знак (60 000 → −60 000), доход и статья без
     * направления — как ввели.
     */
    public static function fromInput(string $amount, PLFlow $flow): string
    {
        $amount = bcadd($amount, '0', 2);

        return PLFlow::EXPENSE === $flow ? bcsub('0', $amount, 2) : $amount;
    }

    /**
     * Знак суммы по направлению статьи: расход — минус, доход — плюс, без направления — как есть.
     */
    public static function signByFlow(string $amount, PLFlow $flow): string
    {
        $abs = ltrim($amount, '-');
        if (0 === bccomp($abs, '0', 2)) {
            return '0.00';
        }

        return match ($flow) {
            PLFlow::EXPENSE => bcsub('0', $abs, 2),
            PLFlow::INCOME => bcadd($abs, '0', 2),
            PLFlow::NONE => bcadd($amount, '0', 2),
        };
    }

    public static function isPercent(string $value): bool
    {
        return 1 === preg_match('/^\d{1,4}(\.\d{1,2})?$/D', $value) && bccomp($value, self::MAX_PERCENT, 2) <= 0;
    }

    private static function round(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }
}
