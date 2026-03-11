<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\DTO\ParsedProductRow;
use OpenSpout\Reader\XLS\Reader as XlsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Парсит XLS/XLSX файл в массив ParsedProductRow через OpenSpout.
 *
 * Структура шаблона (строка 1 — заголовок, данные с строки 2):
 *   A - Наименование       — обязательно
 *   B - Артикул продавца   — необязательно
 *   C - Баркод(ы)          — необязательно, несколько через ";" или ","
 *   D - Закупочная цена    — необязательно, 0 допустим
 *   E - Валюта             — необязательно, по умолчанию RUB
 */
final class XlsProductRowParser
{
    private const HEADER_ROW = 1;

    private const COL_NAME       = 0; // A
    private const COL_VENDOR_SKU = 1; // B
    private const COL_BARCODES   = 2; // C
    private const COL_PRICE      = 3; // D
    private const COL_CURRENCY   = 4; // E

    /**
     * @return ParsedProductRow[]
     *
     * @throws \RuntimeException если файл не найден или формат не поддерживается
     */
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException(sprintf('Import file not found: %s', $filePath));
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx'  => $this->parseWithReader(new XlsxReader(), $filePath),
            'xls'   => $this->parseWithReader(new XlsReader(), $filePath),
            default => throw new \RuntimeException(
                sprintf('Unsupported file extension "%s". Allowed: xls, xlsx.', $extension)
            ),
        };
    }

    /**
     * @param XlsReader|XlsxReader $reader
     *
     * @return ParsedProductRow[]
     */
    private function parseWithReader(XlsReader|XlsxReader $reader, string $filePath): array
    {
        $reader->open($filePath);

        $rows      = [];
        $rowNumber = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            // Читаем только первый лист
            foreach ($sheet->getRowIterator() as $row) {
                ++$rowNumber;

                if ($rowNumber === self::HEADER_ROW) {
                    continue;
                }

                $cells = $row->getCells();

                $name = $this->cellString($cells, self::COL_NAME);

                // Пропускаем полностью пустые строки
                if (null === $name || '' === $name) {
                    continue;
                }

                $rows[] = new ParsedProductRow(
                    rowNumber:  $rowNumber,
                    name:       $name,
                    vendorSku:  $this->cellString($cells, self::COL_VENDOR_SKU),
                    barcodes:   $this->cellString($cells, self::COL_BARCODES),
                    priceAmount: $this->cellNumericAsString($cells, self::COL_PRICE),
                    currency:   $this->cellString($cells, self::COL_CURRENCY),
                );
            }

            // Только первый лист
            break;
        }

        $reader->close();

        return $rows;
    }

    private function cellString(array $cells, int $index): ?string
    {
        if (!isset($cells[$index])) {
            return null;
        }

        $value = $cells[$index]->getValue();

        if (null === $value || '' === (string) $value) {
            return null;
        }

        return trim((string) $value);
    }

    private function cellNumericAsString(array $cells, int $index): ?string
    {
        if (!isset($cells[$index])) {
            return null;
        }

        $value = $cells[$index]->getValue();

        if (null === $value || '' === (string) $value) {
            return null;
        }

        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return trim((string) $value);
    }
}
