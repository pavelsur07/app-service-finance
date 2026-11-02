<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\YooKassa;

/**
 * Сумма ЮKassa — строка с точкой и двумя знаками (`"4970.00"`); у нас — копейки. Без float.
 */
final class YooKassaAmount
{
    public static function format(int $kopecks): string
    {
        if ($kopecks < 0) {
            throw new \InvalidArgumentException('Сумма не может быть отрицательной.');
        }

        return sprintf('%d.%02d', intdiv($kopecks, 100), $kopecks % 100);
    }

    /** null — строка не похожа на сумму ЮKassa. */
    public static function parse(mixed $value): ?int
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
