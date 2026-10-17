<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Export;

use App\Finance\Enum\PLValueFormat;
use App\Marketplace\Application\Action\BuildMarketplacePlPreviewAction;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Application\DTO\PlPreviewCostCoverageDTO;
use App\Marketplace\Enum\PlPreviewColumnKind;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Предпросмотр ОПиУ в xlsx — те же данные, что на вкладке. Оформление — как у выгрузки ОПиУ
 * (Finance\Infrastructure\Export\PlReportXlsxExporter): суммы числами с бухгалтерским форматом.
 */
final readonly class PlPreviewXlsxExporter
{
    private const string HEADER_BG_COLOR = '2563EB';
    private const string MONEY_FORMAT = '#,##0.00;(#,##0.00);0.00';

    public function export(MarketplacePlPreviewReport $report, bool $showEmptyRows, string $outputPath): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);

        try {
            $this->writeReportSheet($writer, $report, $showEmptyRows);

            $writer->addNewSheetAndMakeItCurrent();
            $this->writeLeftOutSheet($writer, $report);

            $writer->addNewSheetAndMakeItCurrent();
            $writer->getCurrentSheet()->setName('Исключено');
            $this->writeCostTable($writer, $report->excludedCosts());

            $writer->addNewSheetAndMakeItCurrent();
            $this->writeReconciliationSheet($writer, $report);
        } finally {
            $writer->close();
        }
    }

    private function writeReportSheet(Writer $writer, MarketplacePlPreviewReport $report, bool $showEmptyRows): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('ОПиУ по тегам');
        $sheet->setColumnWidth(45, 1);
        $sheet->setColumnWidthForRange(18, 2, 1 + \count($report->columns));

        $writer->addRow(Row::fromValues([sprintf(
            'Предпросмотр ОПиУ: %s — %s, %s',
            $report->from->format('d.m.Y'),
            $report->to->format('d.m.Y'),
            $report->marketplace?->getDisplayName() ?? 'все маркетплейсы',
        )], (new Style())->setFontBold()));
        if ($report->tagsOverlap) {
            $writer->addRow(Row::fromValues(['Листинг с несколькими тегами входит в каждый из них полной суммой — сумма колонок тегов не равна «Итого».']));
        }
        $writer->addRow(Row::fromValues([]));

        $headers = ['Статья'];
        foreach ($report->columns as $column) {
            $headers[] = $column->label;
        }
        $writer->addRow($this->headerRow($headers));

        $rows = $report->visibleRows($showEmptyRows);
        foreach ($rows as $index => $row) {
            $hasChildren = isset($rows[$index + 1]) && $rows[$index + 1]->level > $row->level;
            $valueStyle = (new Style())->setFormat(self::numberFormat($row->format))->setCellAlignment(CellAlignment::RIGHT);
            $totalStyle = (clone $valueStyle)->setFontBold();

            $cells = [self::text(str_repeat("\u{00A0}\u{00A0}", max(0, $row->level - 1)).$row->name)];
            foreach ($report->columns as $column) {
                $cells[] = Cell::fromValue(
                    $row->values[$column->key] ?? 0.0,
                    PlPreviewColumnKind::TOTAL === $column->kind ? $totalStyle : $valueStyle,
                );
            }
            $writer->addRow(new Row($cells, $hasChildren ? (new Style())->setFontBold() : null));
        }
    }

    private function writeLeftOutSheet(Writer $writer, MarketplacePlPreviewReport $report): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Не попадёт в ОПиУ');
        $sheet->setColumnWidth(20, 1);
        $sheet->setColumnWidth(45, 2);
        $sheet->setColumnWidthForRange(16, 3, 4);

        $bold = (new Style())->setFontBold();
        $money = (new Style())->setFormat(self::MONEY_FORMAT);

        $writer->addRow(Row::fromValues(['Затраты без решения по ОПиУ'], $bold));
        $this->writeCostTable($writer, $report->unmappedCosts());
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['Продажи и возвраты без правила ОПиУ'], $bold));
        $writer->addRow($this->headerRow(['Маркетплейс', 'Вид', 'Строк', 'Сумма, ₽']));
        foreach ($report->salesWithoutMapping() as $row) {
            $writer->addRow(new Row([
                self::text($row->marketplace->getDisplayName()),
                self::text($row->kind->label()),
                Cell::fromValue($row->rowsCount),
                Cell::fromValue((float) $row->amount, $money),
            ]));
        }
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['Правила ведут на статьи, которых нет в отчёте ОПиУ'], $bold));
        $writer->addRow($this->headerRow(['Статья', 'Причина', 'Сумма, ₽']));
        foreach ($report->unplaced as $item) {
            $writer->addRow(new Row([
                self::text($item->categoryName ?? 'Статья удалена или недоступна'),
                self::text($item->reason->label()),
                Cell::fromValue((float) ($item->values[BuildMarketplacePlPreviewAction::TOTAL_KEY] ?? '0'), $money),
            ]));
        }
    }

    private function writeReconciliationSheet(Writer $writer, MarketplacePlPreviewReport $report): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Сверка');
        $sheet->setColumnWidth(45, 1);
        $sheet->setColumnWidthForRange(18, 2, 6);

        $money = (new Style())->setFormat(self::MONEY_FORMAT);
        $byStatus = $report->costTotalsByStatus();

        $writer->addRow($this->headerRow(['Затраты периода', 'Сумма, ₽']));
        foreach ([
            'Всего в данных маркетплейсов' => $report->costTotal,
            'Размечено в ОПиУ' => $byStatus['mapped'],
            'Исключено из ОПиУ' => $byStatus['excluded'],
            'Не размечено' => $byStatus['unmapped'],
            'Расхождение' => $report->costReconciliationDelta,
        ] as $label => $amount) {
            $writer->addRow(new Row([self::text($label), Cell::fromValue((float) $amount, $money)]));
        }

        if ([] !== $report->adReconciliation) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(new Row([self::text('Реклама: учёт и статистика (в колонках тегов — по статистике)')], (new Style())->setFontBold()));
            $writer->addRow($this->headerRow(['Маркетплейс', 'Учёт, ₽', 'Статистика, ₽', 'На товарах, ₽', 'Без товара, ₽', 'Расхождение, ₽']));
            foreach ($report->adReconciliation as $ads) {
                $writer->addRow(new Row([
                    self::text($ads->marketplace->getDisplayName()),
                    Cell::fromValue((float) $ads->ledger, $money),
                    Cell::fromValue((float) $ads->statistics, $money),
                    Cell::fromValue((float) $ads->onListings, $money),
                    Cell::fromValue((float) $ads->unallocated, $money),
                    Cell::fromValue((float) $ads->difference, $money),
                ]));
            }
        }

        if ([] !== $report->warnings) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(['Предупреждения'], (new Style())->setFontBold()));
            foreach ($report->warnings as $warning) {
                $writer->addRow(new Row([self::text($warning)]));
            }
        }
    }

    /**
     * @param list<PlPreviewCostCoverageDTO> $rows
     */
    private function writeCostTable(Writer $writer, array $rows): void
    {
        $money = (new Style())->setFormat(self::MONEY_FORMAT);
        $writer->addRow($this->headerRow(['Маркетплейс', 'Категория затрат', 'Код', 'Строк', 'Сумма, ₽']));
        foreach ($rows as $row) {
            $writer->addRow(new Row([
                self::text($row->marketplace->getDisplayName()),
                self::text($row->costCategoryName ?? 'Без категории'),
                self::text($row->costCategoryCode ?? ''),
                Cell::fromValue($row->rowsCount),
                Cell::fromValue((float) $row->netAmount, $money),
            ]));
        }
    }

    /**
     * @param list<string> $headers
     */
    private function headerRow(array $headers): Row
    {
        return new Row(array_map(self::text(...), $headers), (new Style())
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(self::HEADER_BG_COLOR));
    }

    /**
     * Строка из пользовательских названий (теги, статьи) — всегда текст: Cell::fromValue() делает
     * формулу из любой строки на «=».
     */
    private static function text(string $value): StringCell
    {
        return new StringCell($value, null);
    }

    private static function numberFormat(PLValueFormat $format): string
    {
        return match ($format) {
            PLValueFormat::PERCENT => '0.0%;(0.0%);0.0%',
            PLValueFormat::RATIO => '0.0000;(0.0000);0.0000',
            PLValueFormat::MONEY, PLValueFormat::QTY => self::MONEY_FORMAT,
        };
    }
}
