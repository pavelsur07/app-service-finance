<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Infrastructure\Export;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Enum\PLValueFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

final readonly class PlProjectionXlsExporter
{
    /** @param list<PlPreviewRow> $rows */
    public function write(array $rows, string $path): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('ОПиУ');
        $sheet->fromArray(['Статья', 'Новый расчёт']);
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(48);
        $sheet->getColumnDimension('B')->setWidth(20);

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $sheet->setCellValueExplicit('A'.$line, $row->name, DataType::TYPE_STRING);
            $sheet->getStyle('A'.$line)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(min(15, max(0, $row->level - 1)));
            $sheet->setCellValue('B'.$line, $row->values['new'] ?? 0.0);
            $sheet->getStyle('B'.$line)->getNumberFormat()->setFormatCode(match ($row->format) {
                PLValueFormat::PERCENT => '0.0%;(0.0%)',
                PLValueFormat::RATIO => '0.0000;(0.0000)',
                PLValueFormat::MONEY, PLValueFormat::QTY => '#,##0.00;(#,##0.00)',
            });
        }

        (new Xls($book))->save($path);
    }
}
