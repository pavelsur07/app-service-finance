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
     * Якоря секций — текст в первой текстовой ячейке строки, обозначающий начало секции.
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

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $sheetTitle = $sheet->getTitle();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $maxColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

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
            // Ищем первую текстовую ячейку в строке (label) и последнее число (amount)
            $rowData = $this->scanRow($sheet, $row, $maxColIndex);

            if (null === $rowData['label']) {
                continue;
            }

            $label = $rowData['label'];
            $labelNormalized = $this->normalize($label);
            $rowsParsed++;

            // Проверяем, это итоговая строка?
            $totalKey = $this->matchAnchor($labelNormalized, self::TOTAL_ANCHORS);
            if (null !== $totalKey) {
                $totals[$totalKey] = $rowData['amount'];
                continue;
            }

            // Проверяем, это начало новой секции?
            $sectionKey = $this->matchAnchor($labelNormalized, self::SECTION_ANCHORS);
            if (null !== $sectionKey) {
                $sections[] = [
                    'name' => $sectionKey,
                    'title' => trim($label),
                    'rows' => [],
                ];
                $currentSection = &$sections[array_key_last($sections)];
                continue;
            }

            // Если мы внутри секции — ищем строки label + amount
            if (null !== $currentSection) {
                if (null === $rowData['amount']) {
                    continue;
                }

                $currentSection['rows'][] = [
                    'name' => trim($label),
                    'amount' => $rowData['amount'],
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
     * Сканирует строку: возвращает первый текстовый label и последнее число (amount).
     *
     * Ищет по всем колонкам (не только A), что делает парсер устойчивым
     * к отчётам, где данные начинаются не с первой колонки.
     *
     * @return array{label: ?string, amount: ?float, label_col: ?int}
     */
    private function scanRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, int $maxCol): array
    {
        $label = null;
        $labelCol = null;
        $lastAmount = null;

        for ($col = 1; $col <= $maxCol; $col++) {
            $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            $value = $sheet->getCell($coordinate)->getCalculatedValue();

            if (null === $value || '' === $value) {
                continue;
            }

            // Пробуем как число
            $numericValue = $this->parseNumeric($value);
            if (null !== $numericValue) {
                $lastAmount = $numericValue;
                continue;
            }

            // Текстовое значение — берём первое как label
            if (null === $label && is_string($value)) {
                $trimmed = trim($value);
                if ('' !== $trimmed) {
                    $label = $trimmed;
                    $labelCol = $col;
                }
            }
        }

        return [
            'label' => $label,
            'amount' => $lastAmount,
            'label_col' => $labelCol,
        ];
    }

    /**
     * Пробует интерпретировать значение как число.
     */
    private function parseNumeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $cleaned = (string) preg_replace('/\s+/u', '', $value);
            $cleaned = str_replace(',', '.', $cleaned);
            if ('' !== $cleaned && is_numeric($cleaned)) {
                return (float) $cleaned;
            }
        }

        return null;
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

    private function normalize(string $text): string
    {
        $text = trim($text);
        $text = mb_strtolower($text);
        // Убираем множественные пробелы
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return $text;
    }
}
