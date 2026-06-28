<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * Стратегии округления денежной величины до целых минорных единиц.
 *
 * Все вычисления — через bcmath, без float.
 */
enum RoundingMode
{
    /** Половина — от нуля (1.5 → 2, -1.5 → -2). Финансовый дефолт. */
    case HALF_UP;

    /** Банковское округление: половина — к чётному (0.5 → 0, 1.5 → 2, 2.5 → 2). */
    case HALF_EVEN;

    /**
     * Округляет bcmath-decimal до целого (строка без дробной части).
     */
    public function roundToInteger(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '0';
        }

        $negative = str_starts_with($value, '-');
        $abs = ltrim($negative ? substr($value, 1) : $value, '+');
        if ('' === $abs) {
            $abs = '0';
        }

        $dot = strpos($abs, '.');
        $fracLen = false === $dot ? 0 : strlen($abs) - $dot - 1;

        $intPart = \bcadd($abs, '0', 0); // усечение к нулю

        if (0 === $fracLen) {
            $resultAbs = $intPart;
        } else {
            $frac = \bcsub($abs, $intPart, $fracLen);
            $cmp = \bccomp($frac, '0.5', $fracLen);

            $roundUp = match ($this) {
                self::HALF_UP => $cmp >= 0,
                self::HALF_EVEN => $cmp > 0 || (0 === $cmp && '0' !== \bcmod($intPart, '2')),
            };

            $resultAbs = $roundUp ? \bcadd($intPart, '1', 0) : $intPart;
        }

        return $negative && '0' !== $resultAbs ? '-'.$resultAbs : $resultAbs;
    }
}
