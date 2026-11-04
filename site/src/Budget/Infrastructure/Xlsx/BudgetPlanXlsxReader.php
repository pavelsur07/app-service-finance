<?php

declare(strict_types=1);

namespace App\Budget\Infrastructure\Xlsx;

use App\Budget\Application\DTO\BudgetPlanFile;
use App\Budget\Application\DTO\BudgetPlanFileRow;
use App\Budget\Domain\BudgetAmount;
use App\Budget\Exception\InvalidBudgetInputException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Читает файл плана в раскладке BudgetPlanSheet: лист «План» (нет такого — первый), служебная
 * строка, заголовок таблицы (ищется по колонке «ID статьи»), строки статей. Суммы — числа или
 * текст «1 200,50», пустая ячейка — 0; не сумма (текст, формула, больше двух знаков) — null:
 * ошибка только для строки, которая загружается (KPI с четырьмя знаками в выгрузке — норма).
 * Что за статья и куда писать — решает BudgetImportPlanner.
 */
final readonly class BudgetPlanXlsxReader
{
    public const int MAX_ROWS = 2000;
    private const int HEADER_SEARCH_ROWS = 30;

    public function read(string $path): BudgetPlanFile
    {
        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        try {
            $reader->open($path);
            $sheetIndex = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetIndex ??= $sheet->getIndex();
                if (BudgetPlanSheet::SHEET_NAME === $sheet->getName()) {
                    $sheetIndex = $sheet->getIndex();
                    break;
                }
            }

            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getIndex() !== $sheetIndex) {
                    continue;
                }

                return $this->readSheet($sheet->getRowIterator());
            }
        } catch (InvalidBudgetInputException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new InvalidBudgetInputException('Файл не читается как xlsx — выгрузите план кнопкой «Excel» и загрузите его.');
        } finally {
            $reader->close();
        }

        throw new InvalidBudgetInputException('В файле нет листов.');
    }

    /**
     * @param iterable<int, \OpenSpout\Common\Entity\Row> $rows
     */
    private function readSheet(iterable $rows): BudgetPlanFile
    {
        $meta = ['year' => null, 'project' => null, 'center' => null];
        $headerFound = false;
        $result = [];
        foreach ($rows as $line => $row) {
            $cells = array_map(static fn ($cell): mixed => $cell->getValue(), $row->getCells());
            if (!$headerFound) {
                if (BudgetPlanSheet::META_LABEL === self::text($cells[0] ?? null)) {
                    $meta = BudgetPlanSheet::parseMeta(self::text($cells[1] ?? null));
                }
                if ('ID статьи' === self::text($cells[BudgetPlanSheet::COL_ID] ?? null)) {
                    $headerFound = true;
                } elseif ($line >= self::HEADER_SEARCH_ROWS) {
                    break;
                }
                continue;
            }

            $id = self::text($cells[BudgetPlanSheet::COL_ID] ?? null);
            $code = self::text($cells[BudgetPlanSheet::COL_CODE] ?? null);
            $name = self::text($cells[BudgetPlanSheet::COL_NAME] ?? null);
            if ('' === $id && '' === $code && '' === $name) {
                continue;
            }
            if (\count($result) >= self::MAX_ROWS) {
                throw new InvalidBudgetInputException(sprintf('В файле больше %d строк.', self::MAX_ROWS));
            }

            $amounts = [];
            foreach (array_keys(BudgetPlanSheet::MONTH_NAMES) as $offset) {
                $amounts[$offset + 1] = self::amount($cells[BudgetPlanSheet::COL_FIRST_MONTH + $offset] ?? null);
            }
            $result[] = new BudgetPlanFileRow($line, '' === $id ? null : $id, '' === $code ? null : $code, $name, $amounts);
        }

        if (!$headerFound) {
            throw new InvalidBudgetInputException('Не найдена таблица плана: нужна строка заголовка с колонкой «ID статьи» — выгрузите план кнопкой «Excel».');
        }

        return new BudgetPlanFile($meta['year'], $meta['project'], $meta['center'], $result);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? trim((string) $value) : '';
    }

    /**
     * Значение ячейки → сумма с двумя знаками или null, если это не сумма.
     */
    private static function amount(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return '0.00';
        }
        if (\is_int($value)) {
            $value = (string) $value;
        } elseif (\is_float($value)) {
            if (!is_finite($value) || abs($value * 100 - round($value * 100)) > 1e-6) {
                return null;
            }
            $value = sprintf('%.2f', round($value, 2));
        } elseif (\is_string($value)) {
            $value = str_replace(["\u{00A0}", "\u{202F}", ' ', "\u{2212}", ','], ['', '', '', '-', '.'], trim($value));
            if ('' === $value) {
                return '0.00';
            }
        } else {
            return null;
        }
        if (!BudgetAmount::isValid($value)) {
            return null;
        }

        return bcadd($value, '0', 2);
    }
}
