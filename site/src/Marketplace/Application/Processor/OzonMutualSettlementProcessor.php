<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Shared\Service\AppLogger;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Парсит XLSX «Отчёт о взаиморасчётах» Ozon в структурированный JSON.
 *
 * Данные лежат в конкретных колонках (B, E, G, I, L), а не подряд.
 * Колонки C, D, F, H, J, K — пустые или визуальные разделители/merged cells.
 *
 * Структура результата:
 *   - source_endpoint: string
 *   - document_info: {payer_name, payer_inn, payer_kpp, receiver_name, receiver_inn, receiver_kpp, contract, period_from, period_to}
 *   - opening_balance: {date, debit, credit}
 *   - closing_balance: {date, debit, credit}
 *   - rows[]: массив операций {type, document_number, document_date, row_date, debit, credit}
 *   - compensations[]: массив компенсаций {name, sum_no_vat, sum_with_vat, vat_amount}
 *   - totals: {rows_count, total_debit, total_credit}
 *   - meta: {sheet_title, total_rows_in_sheet, rows_parsed}
 */
final readonly class OzonMutualSettlementProcessor
{
    public function __construct(
        private AppLogger $appLogger,
    ) {
    }

    /**
     * Парсит XLSX-файл взаиморасчётов.
     *
     * @param string $filePath Абсолютный путь к XLSX-файлу
     *
     * @return array{source_endpoint: string, document_info: array, opening_balance: array, closing_balance: array, rows: array, compensations: array, totals: array, meta: array}
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

        $this->appLogger->info('OzonMSProcessor: начало парсинга', [
            'file' => basename($filePath),
            'sheetTitle' => $sheetTitle,
            'rows' => $highestRow,
        ]);

        $documentInfo = $this->parseDocumentInfo($sheet);
        $operationsData = $this->parseOperations($sheet, $highestRow);
        $compensations = $this->parseCompensations($sheet, $highestRow);

        $result = [
            'source_endpoint' => '/v1/finance/mutual-settlement',
            'document_info' => $documentInfo,
            'opening_balance' => $operationsData['opening_balance'],
            'closing_balance' => $operationsData['closing_balance'],
            'rows' => $operationsData['rows'],
            'compensations' => $compensations,
            'totals' => [
                'rows_count' => count($operationsData['rows']),
                'total_debit' => $operationsData['total_debit'],
                'total_credit' => $operationsData['total_credit'],
            ],
            'meta' => [
                'sheet_title' => $sheetTitle,
                'total_rows_in_sheet' => $highestRow,
                'rows_parsed' => count($operationsData['rows']),
            ],
        ];

        $this->appLogger->info('OzonMSProcessor: парсинг завершён', [
            'rows_parsed' => count($operationsData['rows']),
            'compensations' => count($compensations),
            'opening_balance' => $operationsData['opening_balance'],
            'closing_balance' => $operationsData['closing_balance'],
        ]);

        $spreadsheet->disconnectWorksheets();

        return $result;
    }

    /**
     * Парсит реквизиты документа из заголовочных строк (R1-R12).
     *
     * R2 C: "Отчет о взаиморасчетах"
     * R3 C: "за период с  01.01.2026 по 31.01.2026"
     * R4 C: "по Договору оферты ... № ИР-104604/21  от 31.10.2021"
     * R6 B: "Плательщик:"    J: "Получатель:"
     * R7 B: payer_name        J: receiver_name
     * R8 B: "ИНН" D: inn     J: "ИНН" L: inn
     * R9 B: "КПП" D: kpp     J: "КПП" L: kpp
     */
    private function parseDocumentInfo(Worksheet $sheet): array
    {
        $info = [
            'payer_name' => null,
            'payer_inn' => null,
            'payer_kpp' => null,
            'receiver_name' => null,
            'receiver_inn' => null,
            'receiver_kpp' => null,
            'contract' => null,
            'period_from' => null,
            'period_to' => null,
        ];

        // Ищем период и договор в строках 1-12, колонка C
        for ($row = 1; $row <= 12; $row++) {
            $cellC = $this->getCellStringValue($sheet, 'C', $row);

            // Период: "за период с  01.01.2026 по 31.01.2026"
            if (preg_match('/за\s+период\s+с\s+(\d{2}\.\d{2}\.\d{4})\s+по\s+(\d{2}\.\d{2}\.\d{4})/u', $cellC, $m)) {
                $info['period_from'] = $this->convertDateDmy($m[1]);
                $info['period_to'] = $this->convertDateDmy($m[2]);
            }

            // Договор: "по Договору ... № ИР-104604/21  от 31.10.2021"
            if (preg_match('/№\s*(.+?)\s{1,}от\s+(\d{2}\.\d{2}\.\d{4})/u', $cellC, $m)) {
                $info['contract'] = trim($m[1]) . ' от ' . $m[2];
            }
        }

        // Ищем реквизиты в строках 1-12
        for ($row = 1; $row <= 12; $row++) {
            $cellB = $this->getCellStringValue($sheet, 'B', $row);
            $normalizedB = $this->normalize($cellB);

            // Строка с "плательщик:" — следующая строка содержит имя
            if (str_contains($normalizedB, 'плательщик')) {
                $info['payer_name'] = $this->getCellStringValueOrNull($sheet, 'B', $row + 1);
                $info['receiver_name'] = $this->getCellStringValueOrNull($sheet, 'J', $row + 1);

                // Ищем ИНН/КПП по меткам в строках после "Плательщик:"
                for ($sub = $row + 1; $sub <= min($row + 6, 15); $sub++) {
                    $label = $this->normalize($this->getCellStringValue($sheet, 'B', $sub));
                    if (str_contains($label, 'инн')) {
                        $info['payer_inn'] = $this->getCellStringValueOrNull($sheet, 'D', $sub);
                        $info['receiver_inn'] = $this->getCellStringValueOrNull($sheet, 'L', $sub);
                    }
                    if (str_contains($label, 'кпп')) {
                        $info['payer_kpp'] = $this->getCellStringValueOrNull($sheet, 'D', $sub);
                        $info['receiver_kpp'] = $this->getCellStringValueOrNull($sheet, 'L', $sub);
                    }
                }

                break;
            }
        }

        return $info;
    }

    /**
     * Парсит таблицу операций.
     *
     * Заголовок: строка где B == "Наименование"
     * Данные: следующие строки до "Конечное сальдо" включительно.
     *
     * Колонки операций:
     *   B — Наименование (тип операции)
     *   E — Документ ("№3648135 от 01.01.2026")
     *   G — Дата
     *   I — Сумма дебиторской задолженности
     *   L — Сумма кредиторской задолженности
     */
    private function parseOperations(Worksheet $sheet, int $highestRow): array
    {
        $openingBalance = ['date' => null, 'debit' => 0.0, 'credit' => 0.0];
        $closingBalance = ['date' => null, 'debit' => 0.0, 'credit' => 0.0];
        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        // Ищем строку-заголовок: B == "Наименование"
        $dataStartRow = null;
        for ($row = 1; $row <= $highestRow; $row++) {
            $cellB = $this->getCellStringValue($sheet, 'B', $row);
            if ('наименование' === $this->normalizeMixedCyrillic($this->normalize($cellB))) {
                $dataStartRow = $row + 1;
                break;
            }
        }

        if (null === $dataStartRow) {
            $this->appLogger->warning('OzonMSProcessor: не найден заголовок таблицы операций (B == "Наименование")');

            return [
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'rows' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
            ];
        }

        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            $cellB = $this->getCellStringValue($sheet, 'B', $row);
            $normalizedB = $this->normalizeMixedCyrillic($this->normalize($cellB));

            if ('' === $normalizedB) {
                continue;
            }

            $debit = $this->parseNumericCell($sheet, 'I', $row);
            $credit = $this->parseNumericCell($sheet, 'L', $row);
            $dateValue = $this->parseDateCell($sheet, 'G', $row);

            // Начальное сальдо
            if (str_contains($normalizedB, 'начальное сальдо')) {
                $openingBalance = [
                    'date' => $dateValue,
                    'debit' => $debit,
                    'credit' => $credit,
                ];
                continue;
            }

            // Конечное сальдо — последняя строка данных
            if (str_contains($normalizedB, 'конечное сальдо')) {
                $closingBalance = [
                    'date' => $dateValue,
                    'debit' => $debit,
                    'credit' => $credit,
                ];
                break;
            }

            // Обычная строка операции
            $cellE = $this->getCellStringValue($sheet, 'E', $row);
            $docInfo = $this->parseDocumentReference($cellE);

            $rows[] = [
                'type' => trim($cellB),
                'document_number' => $docInfo['number'],
                'document_date' => $docInfo['date'],
                'row_date' => $dateValue,
                'debit' => $debit,
                'credit' => $credit,
            ];

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        return [
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'rows' => $rows,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
        ];
    }

    /**
     * Парсит секцию компенсаций.
     *
     * Начало: строка где B содержит "Компенсации и прочие начисления"
     *         (с нормализацией латинской "c" → кириллической "с")
     * Заголовок: строка где B == "Наименование начисления"
     * Колонки:
     *   B — Наименование начисления
     *   F — Сумма без НДС
     *   K — Сумма с НДС
     *   N — В том числе НДС
     * Конец: строка где B == "Итого" или пустая строка после данных.
     */
    private function parseCompensations(Worksheet $sheet, int $highestRow): array
    {
        $compensations = [];

        // Ищем начало секции компенсаций
        $sectionStartRow = null;
        for ($row = 1; $row <= $highestRow; $row++) {
            $cellB = $this->getCellStringValue($sheet, 'B', $row);
            $normalizedB = $this->normalizeMixedCyrillic($this->normalize($cellB));

            if (str_contains($normalizedB, 'компенсации и прочие начисления')) {
                $sectionStartRow = $row;
                break;
            }
        }

        if (null === $sectionStartRow) {
            return [];
        }

        // Ищем заголовок: B == "Наименование начисления"
        $dataStartRow = null;
        for ($row = $sectionStartRow; $row <= min($sectionStartRow + 5, $highestRow); $row++) {
            $cellB = $this->getCellStringValue($sheet, 'B', $row);
            if (str_contains($this->normalize($cellB), 'наименование начисления')) {
                $dataStartRow = $row + 1;
                break;
            }
        }

        if (null === $dataStartRow) {
            return [];
        }

        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            $cellB = $this->getCellStringValue($sheet, 'B', $row);
            $normalizedB = $this->normalize($cellB);

            if ('' === $normalizedB) {
                continue;
            }

            // "Итого" — конец секции
            if ('итого' === $normalizedB) {
                break;
            }

            $compensations[] = [
                'name' => trim($cellB),
                'sum_no_vat' => $this->parseNumericCell($sheet, 'F', $row),
                'sum_with_vat' => $this->parseNumericCell($sheet, 'K', $row),
                'vat_amount' => $this->parseNumericCell($sheet, 'N', $row),
            ];
        }

        return $compensations;
    }

    /**
     * Парсит ссылку на документ из колонки E.
     * Формат: "№3648135 от 01.01.2026"
     *
     * @return array{number: ?string, date: ?string}
     */
    private function parseDocumentReference(string $text): array
    {
        $text = trim($text);
        if ('' === $text) {
            return ['number' => null, 'date' => null];
        }

        $number = null;
        $date = null;

        if (preg_match('/№\s*(\S+)/u', $text, $m)) {
            $number = $m[1];
        }

        if (preg_match('/от\s+(\d{2}\.\d{2}\.\d{4})/u', $text, $m)) {
            $date = $this->convertDateDmy($m[1]);
        }

        return ['number' => $number, 'date' => $date];
    }

    /**
     * Парсит числовое значение из ячейки.
     * Обрабатывает: int, float, строки "1 234 567.89", "1234567,89".
     */
    private function parseNumericCell(Worksheet $sheet, string $column, int $row): float
    {
        $cell = $sheet->getCell($column . $row);
        $value = $cell->getCalculatedValue();

        if (null === $value || '' === $value) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (is_string($value)) {
            $cleaned = (string) preg_replace('/\s+/u', '', $value);
            $cleaned = str_replace(',', '.', $cleaned);
            if (is_numeric($cleaned)) {
                return round((float) $cleaned, 2);
            }
        }

        return 0.0;
    }

    /**
     * Парсит дату из ячейки. Значение может быть DateTime-объектом или строкой "dd.mm.yyyy".
     */
    private function parseDateCell(Worksheet $sheet, string $column, int $row): ?string
    {
        $cell = $sheet->getCell($column . $row);
        $value = $cell->getCalculatedValue();

        if (null === $value || '' === $value) {
            return null;
        }

        // DateTime-объект (PhpSpreadsheet может вернуть)
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // Числовой serial date из Excel
        if (is_numeric($value)) {
            try {
                $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);

                return $dateTime->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        // Строка "dd.mm.yyyy"
        if (is_string($value) && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($value), $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }

        // Строка "yyyy-mm-dd"
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return trim($value);
        }

        return null;
    }

    /**
     * Конвертирует дату dd.mm.yyyy → yyyy-mm-dd.
     */
    private function convertDateDmy(string $dmy): string
    {
        $parts = explode('.', $dmy);

        return sprintf('%s-%s-%s', $parts[2], $parts[1], $parts[0]);
    }

    private function getCellStringValue(Worksheet $sheet, string $column, int $row): string
    {
        $cell = $sheet->getCell($column . $row);
        $value = $cell->getCalculatedValue();

        if (null === $value) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Возвращает строковое значение или null если пусто.
     */
    private function getCellStringValueOrNull(Worksheet $sheet, string $column, int $row): ?string
    {
        $value = $this->getCellStringValue($sheet, $column, $row);
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private function normalize(string $text): string
    {
        $text = trim($text);
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return $text;
    }

    /**
     * Нормализует смешанную кириллицу/латиницу.
     * Заменяет латинские буквы, похожие на кириллические, на кириллические.
     * Нужно для "Компенcации" (латинская "c" вместо кириллической "с").
     */
    private function normalizeMixedCyrillic(string $text): string
    {
        $map = [
            'a' => 'а', 'c' => 'с', 'e' => 'е', 'o' => 'о',
            'p' => 'р', 'x' => 'х', 'y' => 'у', 'k' => 'к',
            'A' => 'А', 'C' => 'С', 'E' => 'Е', 'O' => 'О',
            'P' => 'Р', 'X' => 'Х', 'H' => 'Н', 'K' => 'К',
            'B' => 'В', 'M' => 'М', 'T' => 'Т',
        ];

        return strtr($text, $map);
    }
}
