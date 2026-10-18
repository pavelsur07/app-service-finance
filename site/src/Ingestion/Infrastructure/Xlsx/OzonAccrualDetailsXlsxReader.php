<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Xlsx;

use App\Ingestion\Application\DTO\OzonAccrualDetailsReport;
use App\Ingestion\Application\DTO\OzonAccrualDetailsRow;
use App\Ingestion\Exception\OzonAccrualDetailsFileException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Читает отчёт кабинета Ozon «Детализация начислений» (xlsx): строка 1 —
 * «Период: …», строка 2 — заголовки, дальше начисления.
 *
 * Колонки ищутся по названию, а не по номеру: Ozon уже менял состав отчёта, и
 * сдвиг колонки молча перепутал бы группу с типом. Строки без суммы или даты
 * пропускаются и считаются. Имя файла в сообщения об ошибках не попадает: в нём
 * бывает ФИО продавца.
 */
final readonly class OzonAccrualDetailsXlsxReader
{
    private const COLUMN_DATE = 'Дата начисления';
    private const COLUMN_GROUP = 'Группа услуг';
    private const COLUMN_TYPE = 'Тип начисления';
    private const COLUMN_SKU = 'SKU';
    private const COLUMN_AMOUNT = 'Сумма итого, руб.';

    public function read(string $path): OzonAccrualDetailsReport
    {
        $reader = new Reader(new Options());
        try {
            $reader->open($path);
        } catch (\Throwable $exception) {
            throw new OzonAccrualDetailsFileException('Cannot open the Ozon accrual details file: it is not a readable xlsx.', 0, $exception);
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                return $this->readSheet($sheet->getRowIterator());
            }

            throw new OzonAccrualDetailsFileException('Ozon accrual details file has no sheets.');
        } finally {
            $reader->close();
        }
    }

    /**
     * @param iterable<\OpenSpout\Common\Entity\Row> $rowIterator
     */
    private function readSheet(iterable $rowIterator): OzonAccrualDetailsReport
    {
        $columns = null;
        $rows = [];
        $skipped = 0;
        $rowIndex = 0;

        foreach ($rowIterator as $row) {
            ++$rowIndex;
            $values = array_map(static fn ($cell): mixed => $cell->getValue(), $row->getCells());

            if (null === $columns) {
                $columns = $this->headerColumns($values);
                if (null === $columns && $rowIndex >= 5) {
                    break;
                }
                continue;
            }

            $parsed = $this->parseRow($values, $columns);
            if (null !== $parsed) {
                $rows[] = $parsed;
            } elseif ([] !== array_filter($values, static fn (mixed $value): bool => null !== $value && '' !== $value)) {
                ++$skipped;
            }
        }

        if (null === $columns) {
            throw new OzonAccrualDetailsFileException(sprintf('Ozon accrual details header not found: expected columns «%s».', implode('», «', [self::COLUMN_DATE, self::COLUMN_GROUP, self::COLUMN_TYPE, self::COLUMN_AMOUNT])));
        }

        return new OzonAccrualDetailsReport($rows, $skipped);
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array{date: int, group: int, type: int, sku: ?int, amount: int}|null
     */
    private function headerColumns(array $values): ?array
    {
        $index = [];
        foreach ($values as $position => $value) {
            if (is_string($value)) {
                $index[self::headerKey($value)] = $position;
            }
        }

        foreach ([self::COLUMN_DATE, self::COLUMN_GROUP, self::COLUMN_TYPE, self::COLUMN_AMOUNT] as $required) {
            if (!isset($index[self::headerKey($required)])) {
                return null;
            }
        }

        return [
            'date' => $index[self::headerKey(self::COLUMN_DATE)],
            'group' => $index[self::headerKey(self::COLUMN_GROUP)],
            'type' => $index[self::headerKey(self::COLUMN_TYPE)],
            'sku' => $index[self::headerKey(self::COLUMN_SKU)] ?? null,
            'amount' => $index[self::headerKey(self::COLUMN_AMOUNT)],
        ];
    }

    private static function headerKey(string $header): string
    {
        return mb_strtolower(str_replace('ё', 'е', trim((string) preg_replace('/\s+/u', ' ', $header))));
    }

    /**
     * @param array<int, mixed> $values
     * @param array{date: int, group: int, type: int, sku: ?int, amount: int} $columns
     */
    private function parseRow(array $values, array $columns): ?OzonAccrualDetailsRow
    {
        $date = $this->date($values[$columns['date']] ?? null);
        $amountMinor = $this->amountMinor($values[$columns['amount']] ?? null);
        $group = $this->string($values[$columns['group']] ?? null);
        $type = $this->string($values[$columns['type']] ?? null);
        if (null === $date || null === $amountMinor || null === $group || null === $type) {
            return null;
        }

        return new OzonAccrualDetailsRow(
            date: $date,
            groupLabel: $group,
            typeName: $type,
            sku: null !== $columns['sku'] ? $this->sku($values[$columns['sku']] ?? null) : null,
            amountMinor: $amountMinor,
        );
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $serial = (int) floor((float) $value);

            return $serial > 0 ? (new \DateTimeImmutable('1899-12-30'))->modify(sprintf('+%d days', $serial))->format('Y-m-d') : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            // createFromFormat переносит переполнение (32.08 → 01.09) — сверяем обратно.
            if (false !== $date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function amountMinor(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = str_replace([' ', "\u{00A0}", "\u{202F}", ',', "\u{2212}"], ['', '', '', '.', '-'], trim($value));
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }

        return (int) round(abs((float) $value) * 100);
    }

    private function sku(mixed $value): ?string
    {
        if (is_float($value) || is_int($value)) {
            return $value > 0 ? sprintf('%.0f', $value) : null;
        }

        $value = $this->string($value);
        if (null === $value || 1 !== preg_match('/^(\d+)(?:\.0+)?$/', $value, $matches)) {
            return null;
        }

        $digits = ltrim($matches[1], '0');

        return '' !== $digits ? $digits : null;
    }

    private function string(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' !== $value ? $value : null;
    }
}
