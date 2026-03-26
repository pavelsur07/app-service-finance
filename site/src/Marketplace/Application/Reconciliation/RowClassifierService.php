<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Reconciliation;

/**
 * Шаг 3: классифицирует каждую строку отчёта.
 *
 * ZERO    — amount == 0
 * ACCRUAL — amount > 0 и baseSign == '+'
 * EXPENSE — amount < 0 и baseSign == '-'
 * STORNO  — знак строки не совпадает с baseSign
 */
final class RowClassifierService
{
    public const ZERO    = 'zero';
    public const ACCRUAL = 'accrual';
    public const EXPENSE = 'expense';
    public const STORNO  = 'storno';

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $baseSignMap Map<typeName, baseSign>
     * @return array<int, array<string, mixed>> rows with added 'class' field
     */
    public function classify(array $rows, array $baseSignMap): array
    {
        return array_map(static function (array $row) use ($baseSignMap): array {
            $amount   = (float) $row['amount'];
            $baseSign = $baseSignMap[$row['typeName']] ?? '-';

            $row['class'] = match (true) {
                $amount == 0               => self::ZERO,
                $amount > 0 && $baseSign === '+' => self::ACCRUAL,
                $amount < 0 && $baseSign === '-' => self::EXPENSE,
                default                    => self::STORNO,
            };

            return $row;
        }, $rows);
    }
}
