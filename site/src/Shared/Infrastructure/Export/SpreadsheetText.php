<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Export;

/**
 * Текст из данных (назначение платежа, контрагент, название статьи) для CSV, который
 * откроют в Excel или Google Таблицах: ячейка, начинающаяся с = + - @ или управляющего
 * символа, исполнилась бы как формула. Апостроф впереди табличные редакторы
 * показывают как признак текста.
 *
 * Только для текстовых колонок — суммы вида "-500.00" так не пропускать.
 * В XLSX вместо этого писать `new StringCell(...)`: `Cell::fromValue()` превращает строку
 * на «=» в FormulaCell.
 */
final class SpreadsheetText
{
    public static function safe(string $value): string
    {
        if ('' !== $value && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}
