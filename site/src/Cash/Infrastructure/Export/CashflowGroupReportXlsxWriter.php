<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Export;

use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Application\DTO\CashflowGroupReportLine;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Консолидированный отчёт ДДС группы в xlsx: та же таблица, что на странице. */
final readonly class CashflowGroupReportXlsxWriter
{
    private const FORMAT_MONEY = '#,##0.00';

    public function write(CashflowGroupReport $report, string $outputPath): void
    {
        $bold = (new Style())->setFontBold();
        $money = (new Style())->setFormat(self::FORMAT_MONEY);
        $moneyBold = (new Style())->setFormat(self::FORMAT_MONEY)->setFontBold();

        $writer = new Writer();
        $writer->openToFile($outputPath);
        try {
            $writer->getCurrentSheet()->setName('ДДС группы');
            $writer->addRow(Row::fromValues([sprintf('Консолидированный ДДС, RUB, %s — %s', $report->from->format('d.m.Y'), $report->to->format('d.m.Y'))], $bold));
            if ([] !== $report->companiesWithoutInn) {
                $writer->addRow(Row::fromValues(['Без ИНН (внутригрупповые не распознаются): '.implode(', ', $report->companiesWithoutInn)]));
            }
            $writer->addRow(Row::fromValues(['Статья', ...array_values($report->companies), 'Внутригрупповые', 'Итого по группе'], $bold));
            foreach ([...$report->lines, $report->unmatched, $report->totals] as $line) {
                $writer->addRow($this->line($line, $line->section || $line === $report->totals ? $moneyBold : $money));
            }
            foreach ($report->otherCurrencies as $currency => $amounts) {
                $writer->addRow(Row::fromValues([]));
                $writer->addRow(Row::fromValues(['Другая валюта: '.$currency, ...array_values($report->companies)], $bold));
                $writer->addRow(new Row([Cell::fromValue('Итого'), ...array_map(static fn (string $a): Cell => Cell::fromValue((float) $a, $money), array_values($amounts))]));
            }
        } finally {
            $writer->close();
        }
    }

    private function line(CashflowGroupReportLine $line, Style $style): Row
    {
        $cells = [Cell::fromValue(str_repeat('    ', $line->level - 1).$line->name)];
        foreach ([...array_values($line->amounts), $line->intragroup, $line->total] as $amount) {
            $cells[] = Cell::fromValue((float) $amount, $style);
        }

        return new Row($cells);
    }
}
