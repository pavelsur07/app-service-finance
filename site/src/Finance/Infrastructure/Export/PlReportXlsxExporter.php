<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Export;

use App\Finance\Enum\PLValueFormat;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

final readonly class PlReportXlsxExporter
{
    private const HEADER_BG_COLOR = '2563EB';

    /**
     * @param list<array{id: string, label: string}> $columns
     * @param list<array{id: string, code: ?string, name: string, level: int, type: string}> $rows
     * @param array<string, array<string, float>> $rawValues
     * @param array<string, PLValueFormat> $formats
     */
    public function export(
        string $sheetName,
        array $columns,
        array $rows,
        array $rawValues,
        array $formats,
        bool $showMetaColumns,
        string $outputPath,
    ): void {
        $writer = new Writer();
        $writer->openToFile($outputPath);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName($sheetName);
            $sheet->setColumnWidth(40, 1);
            if ($showMetaColumns) {
                $sheet->setColumnWidth(18, 2, 3);
            }
            if ([] !== $columns) {
                $firstValueColumn = $showMetaColumns ? 4 : 2;
                $sheet->setColumnWidthForRange(18, $firstValueColumn, $firstValueColumn + \count($columns) - 1);
            }

            $writer->addRow($this->buildHeaderRow($columns, $showMetaColumns));

            foreach ($rows as $index => $row) {
                $hasChildren = isset($rows[$index + 1]) && $rows[$index + 1]['level'] > $row['level'];
                $writer->addRow($this->buildDataRow(
                    $row,
                    $columns,
                    $rawValues[$row['id']] ?? [],
                    $formats[$row['id']] ?? PLValueFormat::MONEY,
                    $showMetaColumns,
                    $hasChildren,
                ));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @param list<array{id: string, label: string}> $columns
     */
    private function buildHeaderRow(array $columns, bool $showMetaColumns): Row
    {
        $headers = ['Строка'];
        if ($showMetaColumns) {
            $headers[] = 'Код';
            $headers[] = 'Тип';
        }
        foreach ($columns as $column) {
            $headers[] = $column['label'];
        }

        $style = (new Style())
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(self::HEADER_BG_COLOR)
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);

        return Row::fromValues($headers, $style);
    }

    /**
     * @param array{id: string, code: ?string, name: string, level: int, type: string} $row
     * @param list<array{id: string, label: string}> $columns
     * @param array<string, float> $rawValues
     */
    private function buildDataRow(
        array $row,
        array $columns,
        array $rawValues,
        PLValueFormat $format,
        bool $showMetaColumns,
        bool $hasChildren,
    ): Row {
        $cells = [Cell::fromValue(str_repeat("\u{00A0}\u{00A0}", max(0, $row['level'] - 1)).$row['name'])];
        if ($showMetaColumns) {
            $cells[] = Cell::fromValue($row['code'] ?? '—');
            $cells[] = Cell::fromValue($row['type']);
        }

        $valueStyle = (new Style())
            ->setFormat($this->numberFormat($format))
            ->setCellAlignment(CellAlignment::RIGHT);
        $totalValueStyle = (clone $valueStyle)->setFontBold();
        foreach ($columns as $column) {
            $value = $rawValues[$column['id']] ?? null;
            $style = '_total' === $column['id'] ? $totalValueStyle : $valueStyle;
            $cells[] = Cell::fromValue($value, $style);
        }

        return new Row($cells, $hasChildren ? (new Style())->setFontBold() : null);
    }

    private function numberFormat(PLValueFormat $format): string
    {
        return match ($format) {
            PLValueFormat::PERCENT => '0.0%;(0.0%);0.0%',
            PLValueFormat::RATIO => '0.0000;(0.0000);0.0000',
            PLValueFormat::MONEY, PLValueFormat::QTY => '#,##0.00;(#,##0.00);0.00',
        };
    }
}
