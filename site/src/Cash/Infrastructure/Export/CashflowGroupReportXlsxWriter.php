<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Export;

use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Application\DTO\CashflowGroupReportLine;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Консолидированный отчёт ДДС группы в xlsx: та же таблица, что на странице. */
final readonly class CashflowGroupReportXlsxWriter
{
    private const FORMAT_MONEY = '#,##0.00';
    private const EXCEL_SIGNIFICANT_DIGITS = 15;

    public function write(CashflowGroupReport $report, string $outputPath): void
    {
        $bold = (new Style())->setFontBold();
        $money = (new Style())->setFormat(self::FORMAT_MONEY);
        $moneyBold = (new Style())->setFormat(self::FORMAT_MONEY)->setFontBold();
        $date = (new Style())->setFormat('dd.mm.yyyy');

        $writer = new Writer();
        $writer->openToFile($outputPath);
        // OpenSpout сериализует NumericCell строковым приведением PHP (precision по умолчанию 14).
        $previousPrecision = ini_set('precision', (string) self::EXCEL_SIGNIFICANT_DIGITS);
        try {
            $writer->getCurrentSheet()->setName('ДДС группы');
            $writer->addRow(Row::fromValues([sprintf('Консолидированный ДДС, RUB, %s — %s', $report->from->format('d.m.Y'), $report->to->format('d.m.Y'))], $bold));
            if ([] !== $report->companiesWithoutInn) {
                $writer->addRow(Row::fromValues(['Без ИНН (внутригрупповые не распознаются): '.implode(', ', $report->companiesWithoutInn)]));
            }
            $writer->addRow(Row::fromValues(['Данные по счетам'], $bold));
            $writer->addRow(Row::fromValues(['Счёт', 'Последняя операция', 'Актуальность'], $bold));
            foreach ($report->accounts as $account) {
                $writer->addRow(new Row([
                    new StringCell($report->companies[$account->companyId].' · '.$account->label, null),
                    Cell::fromValue(null === $account->lastDate ? null : new \DateTimeImmutable($account->lastDate), $date),
                    Cell::fromValue(null === $account->lastDate ? 'Нет операций' : ($account->lagging ? 'Отстаёт' : '')),
                ]));
            }
            $companyCells = array_map(static fn (string $name): Cell => new StringCell($name, null), array_values($report->companies));
            $writer->addRow(new Row([Cell::fromValue('Статья'), ...$companyCells, Cell::fromValue('Элиминация'), Cell::fromValue('Итого по группе')], $bold));
            foreach ([...$report->lines, $report->unmatched, $report->totals] as $line) {
                $writer->addRow($this->line($line, $line->section || $line === $report->totals ? $moneyBold : $money));
            }
            $writer->addRow(Row::fromValues(['Сверка', 'Операций в периоде', 'Сумма, RUB'], $bold));
            $reconciliation = $report->reconciliation;
            $writer->addRow(new Row([Cell::fromValue('Деньги в пути (пара за границей периода)'), Cell::fromValue(\count($reconciliation->inTransit)), self::moneyCell($reconciliation->transitNet, $money)]));
            $writer->addRow(new Row([Cell::fromValue('Без пары'), Cell::fromValue(\count($reconciliation->unpaired)), self::moneyCell($reconciliation->unpairedNet, $money)]));
            $writer->addRow(new Row([Cell::fromValue('Итого без несопоставленных'), Cell::fromValue(null), self::moneyCell($report->withoutUnpaired, $moneyBold)]));
            foreach ($report->otherCurrencies as $currency => $amounts) {
                $writer->addRow(Row::fromValues([]));
                $writer->addRow(new Row([Cell::fromValue('Другая валюта: '.$currency), ...$companyCells], $bold));
                $writer->addRow(new Row([Cell::fromValue('Итого'), ...array_map(static fn (string $amount): Cell => self::moneyCell($amount, $money), array_values($amounts))]));
            }
        } finally {
            if (false !== $previousPrecision) {
                ini_set('precision', $previousPrecision);
            }
            $writer->close();
        }
    }

    private function line(CashflowGroupReportLine $line, Style $style): Row
    {
        $cells = [new StringCell(str_repeat('    ', $line->level - 1).$line->name.($line->hasUnpaired ? ' ⚠' : ''), null)];
        foreach ([...array_values($line->amounts), $line->elimination, $line->total] as $amount) {
            $cells[] = self::moneyCell($amount, $style);
        }

        return new Row($cells);
    }

    private static function moneyCell(string $amount, Style $style): Cell
    {
        $digits = trim(str_replace(['-', '.'], '', $amount), '0');
        if (\strlen($digits) <= self::EXCEL_SIGNIFICANT_DIGITS) {
            $number = (float) $amount;
            // Проверяем именно тот текст, который OpenSpout передаст Excel.
            if (0 === bccomp($amount, sprintf('%.2F', (float) (string) $number), 2)) {
                return Cell::fromValue($number, $style);
            }
        }

        return new StringCell($amount, $style);
    }
}
