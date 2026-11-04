<?php

declare(strict_types=1);

namespace App\Budget\Infrastructure\Xlsx;

/**
 * Раскладка листа «План» в xlsx бюджета — общая для выгрузки и загрузки (задача pl-budget, часть 4).
 *
 * Строки 1–3 — шапка для человека, строка 4 — служебная (`year=…;project=…;center=…`), строка 6 —
 * заголовок таблицы, дальше — дерево ОПиУ. Колонки: код, статья, 12 месяцев, год, ID статьи.
 * Листья с направлением «расход» — в знаке ввода (положительные); итоги и показатели — в знаке отчёта.
 */
final class BudgetPlanSheet
{
    public const string SHEET_NAME = 'План';
    public const string META_LABEL = 'Служебная строка — не менять';
    public const int META_ROW = 4;
    public const int HEADER_ROW = 6;

    /** Индексы колонок с нуля. */
    public const int COL_CODE = 0;
    public const int COL_NAME = 1;
    public const int COL_FIRST_MONTH = 2;
    public const int COL_TOTAL = 14;
    public const int COL_ID = 15;

    public const array MONTH_NAMES = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

    /**
     * @return array{year: ?int, project: ?string, center: ?string}
     */
    public static function parseMeta(string $value): array
    {
        $meta = ['year' => null, 'project' => null, 'center' => null];
        foreach (explode(';', $value) as $part) {
            [$key, $item] = array_pad(explode('=', trim($part), 2), 2, '');
            match ($key) {
                'year' => $meta['year'] = ctype_digit($item) ? (int) $item : null,
                'project' => $meta['project'] = '' === $item ? null : $item,
                'center' => $meta['center'] = '' === $item ? null : $item,
                default => null,
            };
        }

        return $meta;
    }

    public static function meta(int $year, ?string $projectDirectionId, ?string $responsibilityCenterId): string
    {
        return sprintf('year=%d;project=%s;center=%s', $year, $projectDirectionId ?? '', $responsibilityCenterId ?? '');
    }
}
