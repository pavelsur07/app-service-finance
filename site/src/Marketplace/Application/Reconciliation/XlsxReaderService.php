<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Reconciliation;

use App\Marketplace\Exception\ReconciliationFileReadException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Читает xlsx отчёт «Детализация начислений» Ozon через openspout.
 * Возвращает массив сырых строк с полями отчёта.
 */
final class XlsxReaderService
{
    /**
     * @return array{period: string, rows: array<int, array<string, mixed>>}
     */
    public function read(string $absolutePath): array
    {
        $options = new Options();
        $options->SHOULD_LOAD_EMPTY_ROWS = false;

        $reader = new Reader($options);

        try {
            $reader->open($absolutePath);
        } catch (\Throwable $e) {
            throw new ReconciliationFileReadException(sprintf('Не удалось открыть XLSX-файл сверки «%s»: %s', basename($absolutePath), $e->getMessage()), 0, $e);
        }

        try {
            $rows = [];
            $period = '';
            $rowIndex = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    ++$rowIndex;
                    $cells = $row->getCells();
                    $values = array_map(static fn ($cell) => $cell->getValue(), $cells);

                    // Строка 1 — период "Период: 01.01.2026-31.01.2026"
                    if (1 === $rowIndex) {
                        $period = (string) ($values[0] ?? '');
                        continue;
                    }

                    // Строка 2 — заголовки
                    if (2 === $rowIndex) {
                        continue;
                    }

                    $amount = $values[15] ?? null;
                    if (null === $amount || '' === $amount) {
                        continue;
                    }

                    $rows[] = [
                        'id' => $values[0] ?? null,
                        'date' => $values[1] ?? null,
                        'serviceGroup' => (string) ($values[2] ?? ''),
                        'typeName' => (string) ($values[3] ?? ''),
                        'article' => $values[4] ?? null,
                        'sku' => $values[5] ?? null,
                        'productName' => $values[6] ?? null,
                        'quantity' => (int) ($values[7] ?? 0),
                        'price' => (float) ($values[8] ?? 0),
                        'orderDate' => $values[9] ?? null,
                        'platform' => $values[10] ?? null,
                        'workScheme' => $values[11] ?? null,
                        'ozonFee' => (float) ($values[12] ?? 0),
                        'locIndex' => $values[13] ?? null,
                        'deliveryTime' => $values[14] ?? null,
                        'amount' => (float) $amount,
                    ];
                }
                break; // Только первый лист
            }
        } catch (\Throwable $e) {
            throw new ReconciliationFileReadException(sprintf('Ошибка чтения XLSX-файла сверки «%s»: %s', basename($absolutePath), $e->getMessage()), 0, $e);
        } finally {
            $reader->close();
        }

        return ['period' => $period, 'rows' => $rows];
    }
}
