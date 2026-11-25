<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Export;

use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Application\DTO\CashflowGroupReportLine;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CashflowGroupReportXlsxWriter
{
    private const FORMAT_MONEY = '#,##0.00';
    private const EXCEL_SIGNIFICANT_DIGITS = 15;

    public function __construct(private ?UrlGeneratorInterface $urls = null)
    {
    }

    public function write(CashflowGroupReport $report, string $outputPath): void
    {
        $book = new Spreadsheet();
        // Числовые ячейки XLSX сериализуются через строковое приведение PHP.
        $previousPrecision = ini_set('precision', (string) self::EXCEL_SIGNIFICANT_DIGITS);
        try {
            $sheet = $book->getActiveSheet()->setTitle('ДДС группы');
            $sheet->getColumnDimension('A')->setWidth(52);
            $row = 1;
            self::labels($sheet, $row++, [sprintf('Консолидированный ДДС, RUB, %s — %s', $report->from->format('d.m.Y'), $report->to->format('d.m.Y'))], true);
            if ([] !== $report->companiesWithoutInn) {
                self::labels($sheet, $row++, ['Без ИНН (внутригрупповые не распознаются): '.implode(', ', $report->companiesWithoutInn)]);
            }
            self::labels($sheet, $row++, ['Данные по счетам'], true);
            self::labels($sheet, $row++, ['Счёт', 'Последняя операция', 'Актуальность'], true);
            foreach ($report->accounts as $account) {
                self::labels($sheet, $row, [$report->companies[$account->companyId].' · '.$account->label]);
                if (null === $account->lastDate) {
                    $sheet->setCellValue('C'.$row, 'Нет операций');
                } else {
                    self::date($sheet->getCell('B'.$row), new \DateTimeImmutable($account->lastDate));
                    if ($account->lagging) {
                        $sheet->setCellValue('C'.$row, 'Отстаёт');
                    }
                }
                ++$row;
            }
            $companies = array_values($report->companies);
            self::labels($sheet, $row++, ['Статья', ...$companies, 'Элиминация', 'Итого по группе'], true);
            foreach ([...$report->lines, $report->unmatched, $report->totals] as $line) {
                $this->writeLine($sheet, $row++, $report, $line);
            }
            self::labels($sheet, $row++, ['Сверка', 'Операций в периоде', 'Сумма, RUB'], true);
            $rec = $report->reconciliation;
            foreach ([
                ['Деньги в пути (пара за границей периода)', \count($rec->inTransit), $rec->transitNet],
                ['Без пары', \count($rec->unpaired), $rec->unpairedNet],
                ['Итого без несопоставленных', null, $report->withoutUnpaired],
            ] as [$label, $count, $amount]) {
                self::labels($sheet, $row, [$label]);
                if (null !== $count) {
                    $sheet->setCellValue('B'.$row, $count);
                }
                self::money($sheet->getCell('C'.$row), $amount);
                ++$row;
            }
            $sheet->getStyle('C'.($row - 1))->getFont()->setBold(true);
            foreach ($report->otherCurrencies as $currency => $amounts) {
                ++$row;
                self::labels($sheet, $row++, ['Другая валюта: '.$currency, ...$companies], true);
                self::labels($sheet, $row, ['Итого']);
                foreach (array_values($amounts) as $index => $amount) {
                    self::money($sheet->getCell([$index + 2, $row]), $amount);
                }
                ++$row;
            }
            if ([] !== $rec->inTransit) {
                $this->writeTransit($book->createSheet()->setTitle('Деньги в пути'), $report);
            }
            (new Xlsx($book))->save($outputPath);
        } finally {
            if (false !== $previousPrecision) {
                ini_set('precision', $previousPrecision);
            }
            $book->disconnectWorksheets();
        }
    }

    private function writeLine(Worksheet $sheet, int $row, CashflowGroupReport $report, CashflowGroupReportLine $line): void
    {
        self::labels($sheet, $row, [str_repeat('    ', $line->level - 1).$line->name.($line->hasUnpaired ? ' ⚠' : '')]);
        $amounts = [...array_values($line->amounts), $line->elimination, $line->total];
        foreach ($amounts as $index => $amount) {
            self::money($sheet->getCell([$index + 2, $row]), $amount);
        }
        if ($line->section || $line === $report->totals) {
            $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(1 + \count($amounts)).$row)->getFont()->setBold(true);
        }
        if ([] !== $line->pairIds) {
            $this->link($sheet->getCell([\count($amounts), $row]), $report, ['row' => $line->key, '_fragment' => 'pairs']);
        }
    }

    private function writeTransit(Worksheet $sheet, CashflowGroupReport $report): void
    {
        self::labels($sheet, 1, ['Компания выплаты', 'Счёт выплаты', 'Дата выплаты', 'Компания поступления', 'Счёт поступления', 'Дата поступления', 'Сумма в периоде, RUB'], true);
        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setWidth(25);
        }
        $row = 2;
        $first = $report->from->format('Y-m-d');
        $last = $report->to->format('Y-m-d');
        foreach ($report->reconciliation->inTransit as $outId => $inId) {
            $out = $report->reconciliation->transactions[$outId];
            $in = $report->reconciliation->transactions[$inId];
            self::labels($sheet, $row, [$report->companies[$out->companyId], $out->accountLabel, '', $report->companies[$in->companyId], $in->accountLabel]);
            self::date($sheet->getCell('C'.$row), $out->occurredAt);
            self::date($sheet->getCell('F'.$row), $in->occurredAt);
            $outDate = $out->occurredAt->format('Y-m-d');
            $outInPeriod = $first <= $outDate && $outDate <= $last;
            self::money($sheet->getCell('G'.$row), $outInPeriod ? $out->amount->negate()->toDecimalString() : $in->amount->toDecimalString());
            $this->link($sheet->getCell(($outInPeriod ? 'F' : 'C').$row), $report, ['operation' => $outInPeriod ? $inId : $outId, '_fragment' => 'operation']);
            ++$row;
        }
    }

    /** @param array<string, string> $parameters */
    private function link(Cell $cell, CashflowGroupReport $report, array $parameters): void
    {
        if (null === $this->urls) {
            return;
        }
        $url = $this->urls->generate('report_cashflow_group_reconciliation', [
            'from' => $report->from->format('Y-m-d'),
            'to' => $report->to->format('Y-m-d'),
            ...$parameters,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $cell->getHyperlink()->setUrl($url);
        $cell->getStyle()->getFont()->setUnderline(Font::UNDERLINE_SINGLE)->getColor()->setARGB(Color::COLOR_BLUE);
    }

    /** @param non-empty-list<string> $labels */
    private static function labels(Worksheet $sheet, int $row, array $labels, bool $bold = false): void
    {
        foreach ($labels as $index => $label) {
            $cell = $sheet->getCell([$index + 1, $row]);
            $cell->setValueExplicit($label, DataType::TYPE_STRING);
            if ($bold) {
                $cell->getStyle()->getFont()->setBold(true);
            }
        }
    }

    private static function date(Cell $cell, \DateTimeImmutable $date): void
    {
        $cell->setValueExplicit(Date::dateTimeToExcel($date), DataType::TYPE_NUMERIC);
        $cell->getStyle()->getNumberFormat()->setFormatCode('dd.mm.yyyy');
    }

    private static function money(Cell $cell, string $amount): void
    {
        $digits = trim(str_replace(['-', '.'], '', $amount), '0');
        $number = (float) $amount;
        if (\strlen($digits) <= self::EXCEL_SIGNIFICANT_DIGITS && 0 === bccomp($amount, sprintf('%.2F', (float) (string) $number), 2)) {
            $cell->setValueExplicit($number, DataType::TYPE_NUMERIC);
            $cell->getStyle()->getNumberFormat()->setFormatCode(self::FORMAT_MONEY);
        } else {
            $cell->setValueExplicit($amount, DataType::TYPE_STRING);
        }
    }
}
