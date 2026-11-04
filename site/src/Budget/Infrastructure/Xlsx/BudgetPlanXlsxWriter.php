<?php

declare(strict_types=1);

namespace App\Budget\Infrastructure\Xlsx;

use App\Budget\Application\View\BudgetGridView;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Вариант бюджета в xlsx — лист «План» по раскладке BudgetPlanSheet; данные — та же сетка, что на
 * экране (BudgetGridBuilder). Суммы листьев-расходов — в знаке ввода (положительные), чтобы файл
 * загружался обратно без смены знака; итоги и показатели — как на экране (в знаке отчёта).
 */
final readonly class BudgetPlanXlsxWriter
{
    private const string HEADER_BG_COLOR = '2563EB';
    private const string MONEY_FORMAT = '#,##0.00;-#,##0.00;0.00';

    public function write(
        BudgetGridView $grid,
        string $versionName,
        string $statusLabel,
        ?string $projectDirectionId,
        ?string $responsibilityCenterId,
        string $pairLabel,
        \DateTimeImmutable $exportedAt,
        string $outputPath,
    ): void {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(BudgetPlanSheet::SHEET_NAME);
            $sheet->setColumnWidth(12, 1);
            $sheet->setColumnWidth(45, 2);
            $sheet->setColumnWidthForRange(14, 3, 15);
            $sheet->setColumnWidth(38, 16);

            $writer->addRow(new Row([new StringCell(sprintf('Бюджет ОПиУ: %s, %d', $versionName, $grid->year), null)], (new Style())->setFontBold()));
            $writer->addRow(new Row([new StringCell('Статус: '.$statusLabel, null), new StringCell('Пара: '.$pairLabel, null)]));
            $writer->addRow(new Row([new StringCell(sprintf(
                'Выгружено %s (МСК). Статьи расходов — положительными, как при вводе; итоги и показатели — как на экране. Загрузить обратно можно только в выбранную пару черновика.',
                $exportedAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'),
            ), null)]));
            $writer->addRow(new Row([
                new StringCell(BudgetPlanSheet::META_LABEL, null),
                new StringCell(BudgetPlanSheet::meta($grid->year, $projectDirectionId, $responsibilityCenterId), null),
            ]));
            $writer->addRow(new Row([]));
            $writer->addRow($this->headerRow());

            foreach ($grid->rows as $row) {
                $format = PLValueFormat::from($row->format);
                $style = (new Style())->setFormat(self::numberFormat($format))->setCellAlignment(CellAlignment::RIGHT);
                $leaf = PLCategoryType::LEAF_INPUT->value === $row->type;
                if (!$leaf) {
                    $style->setFontBold();
                }
                $expense = $leaf && 'expense' === $row->flow && PLValueFormat::MONEY === $format;
                $cells = [
                    new StringCell($row->code ?? '', null),
                    new StringCell(str_repeat('    ', max(0, $row->level - 1)).$row->name, $leaf ? null : (new Style())->setFontBold()),
                ];
                foreach ($grid->months as $month) {
                    $cells[] = Cell::fromValue($this->number($row->values[$month] ?? '0', $expense), $style);
                }
                $cells[] = Cell::fromValue($this->number($row->total, $expense), (clone $style)->setFontBold());
                $cells[] = new StringCell($row->id, null);
                $writer->addRow(new Row($cells));
            }
        } finally {
            $writer->close();
        }
    }

    private function headerRow(): Row
    {
        $style = (new Style())->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor(self::HEADER_BG_COLOR);
        $cells = [new StringCell('Код', $style), new StringCell('Статья', $style)];
        foreach (BudgetPlanSheet::MONTH_NAMES as $name) {
            $cells[] = new StringCell($name, $style);
        }
        $cells[] = new StringCell('Год', $style);
        $cells[] = new StringCell('ID статьи', $style);

        return new Row($cells);
    }

    private function number(string $value, bool $negate): float
    {
        $number = (float) $value;

        return $negate && 0.0 !== $number ? -$number : $number;
    }

    private static function numberFormat(PLValueFormat $format): string
    {
        return match ($format) {
            PLValueFormat::PERCENT => '0.0" %"',
            PLValueFormat::RATIO => '0.0000',
            PLValueFormat::QTY => '#,##0.00',
            PLValueFormat::MONEY => self::MONEY_FORMAT,
        };
    }
}
