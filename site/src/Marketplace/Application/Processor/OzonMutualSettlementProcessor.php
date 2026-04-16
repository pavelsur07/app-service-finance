<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Shared\Service\AppLogger;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Парсит XLSX «Отчёт о взаиморасчётах» Ozon в структурированный JSON.
 *
 * Использует anchor-based подход: ищет ключевые ячейки по тексту,
 * а не по фиксированным позициям строк. Это делает парсер устойчивым
 * к изменениям формата отчёта (добавление/удаление строк).
 *
 * Структура результата:
 *   - sections[]: массив секций {name, rows: [{name, amount}]}
 *   - totals: {total_accrued, total_compensation, total_payout}
 *   - meta: {sheet_title, rows_parsed, parsing_warnings[]}
 */
final readonly class OzonMutualSettlementProcessor
{
    /**
     * Якоря секций — текст в ячейке (column A), обозначающий начало секции.
     * Ключ = нормализованный якорь (lowercase, trimmed), значение = имя секции.
     */
    private const SECTION_ANCHORS = [
        'начисления за реализованный товар' => 'charges_sold_goods',
        'начисления за доставку и возвраты' => 'charges_delivery_returns',
        'компенсация недополученного дохода' => 'compensation_lost_income',
        'компенсация' => 'compensation',
        'прочие начисления' => 'other_charges',
        'начисления за услуги' => 'charges_services',
    ];

    /** Якоря итоговых строк */
    private const TOTAL_ANCHORS = [
        'итого к выплате' => 'total_payout',
        'итого начислено' => 'total_accrued',
        'итого начисления' => 'total_accrued',
        'итого компенсация' => 'total_compensation',
    ];

    public function __construct(
        private AppLogger $appLogger,
    ) {
    }

    /**
     * Парсит XLSX-файл взаиморасчётов.
     *
     * @param string $filePath Абсолютный путь к XLSX-файлу
     *
     * @return array{sections: array, totals: array, meta: array}
     *
     * @throws \RuntimeException если файл не читается или не содержит данных
     */
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException(sprintf('Файл не найден: %s', $filePath));
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $sheetTitle = $sheet->getTitle();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        $this->appLogger->info('OzonMSProcessor: начало парсинга', [
            'file' => basename($filePath),
            'sheetTitle' => $sheetTitle,
            'rows' => $highestRow,
            'columns' => $highestColumn,
        ]);

        $sections = [];
        $totals = [];
        $warnings = [];
        $currentSection = null;
        $rowsParsed = 0;

        for ($row = 1; $row <= $highestRow; $row++) {
            $cellA = $this->getCellValue($sheet, 'A', $row);
            $cellANormalized = $this->normalize($cellA);

            if ('' === $cellANormalized) {
                continue;
            }

            $rowsParsed++;

            // Проверяем, это итоговая строка?
            $totalKey = $this->matchAnchor($cellANormalized, self::TOTAL_ANCHORS);
            if (null !== $totalKey) {
                $amount = $this->findAmountInRow($sheet, $row, $highestColumn);
                $totals[$totalKey] = $amount;
                continue;
            }

            // Проверяем, это начало новой секции?
            $sectionKey = $this->matchAnchor($cellANormalized, self::SECTION_ANCHORS);
            if (null !== $sectionKey) {
                // Закрываем предыдущую секцию, начинаем новую
                $currentSection = [
                    'name' => $sectionKey,
                    'title' => trim($cellA),
                    'rows' => [],
                ];
                $sections[] = &$currentSection;
                continue;
            }

            // Если мы внутри секции — ищем строки name + amount
            if (null !== $currentSection) {
                $amount = $this->findAmountInRow($sheet, $row, $highestColumn);

                // Пропускаем строки без суммы (подзаголовки, пустые)
                if (null === $amount) {
                    continue;
                }

                $currentSection['rows'][] = [
                    'name' => trim($cellA),
                    'amount' => $amount,
                ];
            }
        }

        unset($currentSection);

        // Подсчитываем записи
        $totalRows = 0;
        foreach ($sections as $section) {
            $totalRows += count($section['rows']);
        }

        if (0 === $totalRows && empty($totals)) {
            $warnings[] = 'Не найдены строки с данными — возможно, формат отчёта изменился';
        }

        $result = [
            'sections' => $sections,
            'totals' => $totals,
            'meta' => [
                'sheet_title' => $sheetTitle,
                'total_rows_in_sheet' => $highestRow,
                'rows_parsed' => $rowsParsed,
                'data_rows_found' => $totalRows,
                'parsing_warnings' => $warnings,
            ],
        ];

        $this->appLogger->info('OzonMSProcessor: парсинг завершён', [
            'sections' => count($sections),
            'dataRows' => $totalRows,
            'totals' => array_keys($totals),
            'warnings' => count($warnings),
        ]);

        $spreadsheet->disconnectWorksheets();

        return $result;
    }

    /**
     * Ищет числовое значение (сумму) в строке, сканируя колонки B..последняя.
     * Возвращает последнее найденное число (обычно итог в правой колонке).
     */
    private function findAmountInRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $highestColumn): ?float
    {
        $lastAmount = null;
        $colIndex = 2; // B = 2
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($col = $colIndex; $col <= $maxCol; $col++) {
            $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            $cell = $sheet->getCell($coordinate);
            $value = $cell->getCalculatedValue();

            if (null === $value || '' === $value) {
                continue;
            }

            if (is_numeric($value)) {
                $lastAmount = (float) $value;
            } elseif (is_string($value)) {
                // Пробуем распарсить строку вида "1 234 567.89" или "1234567,89"
                $cleaned = str_replace([' ', "\xC2\xA0"], '', $value); // обычный и неразрывный пробел
                $cleaned = str_replace(',', '.', $cleaned);
                if (is_numeric($cleaned)) {
                    $lastAmount = (float) $cleaned;
                }
            }
        }

        return $lastAmount;
    }

    /**
     * Сопоставляет нормализованный текст ячейки с набором якорей.
     *
     * @param array<string, string> $anchors
     */
    private function matchAnchor(string $normalizedText, array $anchors): ?string
    {
        // Точное совпадение
        if (isset($anchors[$normalizedText])) {
            return $anchors[$normalizedText];
        }

        // Совпадение по началу строки (для «Компенсация недополученного дохода за ...»)
        foreach ($anchors as $anchor => $key) {
            if (str_starts_with($normalizedText, $anchor)) {
                return $key;
            }
        }

        return null;
    }

    private function getCellValue(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $column, int $row): string
    {
        $cell = $sheet->getCell($column . $row);
        $value = $cell->getCalculatedValue();

        if (null === $value) {
            return '';
        }

        return (string) $value;
    }

    private function normalize(string $text): string
    {
        $text = trim($text);
        $text = mb_strtolower($text);
        // Убираем множественные пробелы
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return $text;
    }
}
