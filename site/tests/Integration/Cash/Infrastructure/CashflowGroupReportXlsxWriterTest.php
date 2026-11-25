<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Infrastructure;

use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Infrastructure\Export\CashflowGroupReportXlsxWriter;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\IntegrationTestCase;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

final class CashflowGroupReportXlsxWriterTest extends IntegrationTestCase
{
    use IntragroupReportFixture;

    public function testExportsSignedEliminationsWarningsAndAccountDates(): void
    {
        $this->createGroupFixture();
        $this->loadNovemberTransactions();
        $this->fillMissingInns();

        $rows = $this->export($this->report());

        self::assertSame(['Статья', 'Альфа', 'Бета', 'Гамма', 'Элиминация', 'Итого по группе'], $rows['Статья']);
        self::assertSame(-917000, $rows['Прочие поступления ⚠'][4]);
        self::assertSame(917000, $rows['Прочие выплаты'][4]);
        self::assertSame([2, 2750000], array_slice($rows['Без пары'], 1, 2));
        self::assertSame([0, 0], array_slice($rows['Деньги в пути (пара за границей периода)'], 1, 2));
        self::assertSame(-622716.97, $rows['Итого без несопоставленных'][2]);
        $bank = $rows['Альфа · ВТБ ****0001'];
        self::assertInstanceOf(\DateTimeInterface::class, $bank[1]);
        self::assertSame('2026-11-12', $bank[1]->format('Y-m-d'));
        self::assertSame('Отстаёт', $bank[2]);
    }

    public function testExportsPeriodMovementsBalancesAndCrossPeriodPairAsNumbers(): void
    {
        $this->createGroupFixture();
        $this->loadPeriodTransactions();

        $report = $this->report('2026-10-01', '2026-12-31', 'month');
        $rows = $this->export($report);

        self::assertNotNull($report->periods);
        self::assertSame(['Статья', ...array_column($report->periods->columns, 'label'), 'Итого за период'], $rows['Статья']);
        self::assertSame([0, 10000, 2097283.03, 0], array_slice($rows['Остаток на начало'], 1));
        self::assertSame([10000, 2087283.03, 45000, 2142283.03], array_slice($rows['Изменение'], 1));
        self::assertSame([10000, 2097283.03, 2142283.03, 2142283.03], array_slice($rows['Остаток на конец'], 1));
        self::assertSame([0, -40000, 40000, 0], array_slice($rows['Деньги в пути'], 1));
        self::assertSame([0, 2750000, 0, 2750000], array_slice($rows['Без пары'], 1));
        self::assertSame([10000, -662716.97, 45000, -607716.97], array_slice($rows['Итого без несопоставленных'], 1));
        self::assertSame([1, 40000], array_slice($rows['Пары между периодами'], 1, 2));
        self::assertSame([0, 2880000, 40000, 2880000], array_slice($rows['Прочие поступления ⚠'], 1));
    }

    public function testPeriodWorkbookNavigatesToCompaniesAndReconciliationForTheColumn(): void
    {
        $this->createGroupFixture();
        $this->loadPeriodTransactions();
        $report = $this->report('2026-10-01', '2026-12-31', 'month');
        $this->withPeriodSheet($report, static function (Worksheet $sheet, array $rows): void {
            $companies = '/finance/reports/cashflow/group?from=2026-11-01&to=2026-11-30&view=companies';
            $reconciliation = '/finance/reports/cashflow/group/reconciliation?from=2026-11-01&to=2026-11-30';
            self::assertStringEndsWith($companies, $sheet->getCell('C'.$rows['Статья'])->getHyperlink()->getUrl());
            // Одна ссылка на заголовок и две на сверку в каждой колонке, плюс общая пара.
            self::assertCount(4 * 3 + 1, $sheet->getHyperlinkCollection());
            self::assertStringEndsWith($reconciliation.'#unpaired', $sheet->getCell('C'.$rows['Без пары'])->getHyperlink()->getUrl());
            self::assertStringEndsWith($reconciliation.'#in-transit', $sheet->getCell('C'.$rows['Деньги в пути'])->getHyperlink()->getUrl());
        });
    }

    public function testExplicitYearsUseNumericCellsAndKeepAmountsBeyondExcelPrecisionAsExactText(): void
    {
        $this->createGroupFixture();
        $this->addTransaction(36, '0001', '2026-11-04', '9007199254740992.01', 'Внешний', 'OP_IN_OTHER');
        $this->addTransaction(37, '0003', '2027-01-04', '123.45', 'Внешний', 'OP_IN_CUSTOMERS');
        $this->em->flush();

        $report = $this->report('2026-01-01', '2027-12-31', 'year');
        $this->withPeriodSheet($report, static function (Worksheet $sheet, array $rows): void {
            $headers = $rows['Статья'];
            self::assertSame('2026', $sheet->getCell('B'.$headers)->getValue());
            self::assertSame('2027', $sheet->getCell('C'.$headers)->getValue());
            self::assertSame('Итого за период', $sheet->getCell('D'.$headers)->getValue());
            $large = $sheet->getCell('B'.$rows['Прочие поступления']);
            self::assertSame(DataType::TYPE_STRING, $large->getDataType());
            self::assertSame('9007199254740992.01', $large->getValue());
            $small = $sheet->getCell('C'.$rows['Поступления от покупателей']);
            self::assertSame(DataType::TYPE_NUMERIC, $small->getDataType());
            self::assertSame(123.45, $small->getValue());
            $change = $sheet->getCell('D'.$rows['Изменение']);
            self::assertSame(DataType::TYPE_STRING, $change->getDataType());
            self::assertSame('9007199254741115.46', $change->getValue());
        });
    }

    public function testShowsOnlyNovemberLegAsMoneyInTransit(): void
    {
        $this->createGroupFixture();
        $this->fillMissingInns();
        $this->addTransaction(34, '0001', '2026-11-30', '-102.34', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(35, '0003', '2026-12-02', '102.34', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();

        $rows = $this->export($this->report('2026-11-01', '2026-11-30'));

        self::assertSame([1, -102.34], array_slice($rows['Деньги в пути (пара за границей периода)'], 1, 2));
        self::assertSame([0, 0], array_slice($rows['Без пары'], 1, 2));
        self::assertSame(0, $rows['Прочие выплаты'][4]);
        self::assertSame(-102.34, $rows['Прочие выплаты'][5]);
    }

    public function testLinksCrossMonthPeerAndArticlePairsOnTheApplicationHost(): void
    {
        $this->createGroupFixture();
        $this->fillMissingInns();
        $this->addTransaction(1, '0001', '2026-11-05', '-10.00', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(2, '0003', '2026-11-06', '10.00', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->addTransaction(3, '0001', '2026-11-30', '-102.34', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(4, '0003', '2026-12-02', '102.34', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $router = self::getContainer()->get(RouterInterface::class);
        $original = $router->getContext();
        $router->setContext((new RequestContext())->fromRequest(Request::create('https://group.example.test:8443/')));
        $writer = self::getContainer()->get(CashflowGroupReportXlsxWriter::class);
        $file = tempnam(sys_get_temp_dir(), 'cash_group_links_');
        self::assertNotFalse($file);
        try {
            foreach ([
                ['2026-11-01', '2026-11-30', 'F2', 4, -102.34],
                ['2026-12-01', '2026-12-31', 'C2', 3, 102.34],
            ] as [$from, $to, $peerCell, $peerId, $net]) {
                $writer->write($this->report($from, $to), $file);
                $book = (new Xlsx())->load($file);
                try {
                    $sheet = $book->getSheetByName('Деньги в пути');
                    self::assertNotNull($sheet);
                    $base = "https://group.example.test:8443/finance/reports/cashflow/group/reconciliation?from=$from&to=$to";
                    self::assertSame('Альфа', $sheet->getCell('A2')->getValue());
                    self::assertSame('ВТБ ****0001', $sheet->getCell('B2')->getValue());
                    self::assertSame('30.11.2026', $sheet->getCell('C2')->getFormattedValue());
                    self::assertSame('Бета', $sheet->getCell('D2')->getValue());
                    self::assertSame('Сбер ****0003', $sheet->getCell('E2')->getValue());
                    self::assertSame('02.12.2026', $sheet->getCell('F2')->getFormattedValue());
                    self::assertSame($net, $sheet->getCell('G2')->getValue());
                    self::assertSame($base.'&operation='.self::transactionId($peerId).'#operation', $sheet->getCell($peerCell)->getHyperlink()->getUrl());
                    if (4 === $peerId) {
                        $report = $book->getSheet(0);
                        $pairLinks = $report->getHyperlinkCollection();
                        $matching = array_filter($pairLinks, static fn ($link): bool => $base.'&row=OP_IN_OTHER#pairs' === $link->getUrl());
                        self::assertCount(1, $matching);
                        self::assertSame(-10.0, $report->getCell((string) array_key_first($matching))->getValue());
                    }
                } finally {
                    $book->disconnectWorksheets();
                }
            }
        } finally {
            unlink($file);
            $router->setContext($original);
        }
    }

    public function testPreservesCentsBeyondExcelNumericPrecision(): void
    {
        $this->createGroupFixture();
        $this->addTransaction(36, '0001', '2026-11-04', '9007199254740992.01', 'ООО "Бета"', 'OP_IN_OTHER');
        $this->addTransaction(37, '0003', '2026-11-04', '9999999999999.99', 'Внешний', 'OP_IN_CUSTOMERS');
        $this->em->flush();

        $rows = $this->export($this->report());

        self::assertSame('9007199254740992.01', $rows['Прочие поступления ⚠'][1]);
        self::assertSame([1, '9007199254740992.01'], array_slice($rows['Без пары'], 1, 2));
        self::assertSame(9999999999999.99, $rows['Поступления от покупателей'][2]);
        self::assertSame(9999999999999.99, $rows['Итого без несопоставленных'][2]);
    }

    /** @param \Closure(Worksheet, array<string, int>): void $check */
    private function withPeriodSheet(CashflowGroupReport $report, \Closure $check): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cash_period_');
        self::assertNotFalse($file);
        try {
            self::getContainer()->get(CashflowGroupReportXlsxWriter::class)->write($report, $file);
            $book = (new Xlsx())->load($file);
            try {
                $sheet = $book->getSheet(0);
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[trim((string) $sheet->getCell('A'.$row->getRowIndex())->getValue())] = $row->getRowIndex();
                }
                $check($sheet, $rows);
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            unlink($file);
        }
    }

    /** @return array<string, list<bool|int|float|string|\DateTimeInterface|\DateInterval|null>> */
    private function export(CashflowGroupReport $report): array
    {
        $file = tempnam(sys_get_temp_dir(), 'cash_group_xlsx_');
        self::assertNotFalse($file);
        $reader = new Reader();
        try {
            (new CashflowGroupReportXlsxWriter())->write($report, $file);
            $reader->open($file);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    if (isset($values[0]) && \is_string($values[0])) {
                        $rows[trim($values[0])] = $values;
                    }
                }
            }

            return $rows;
        } finally {
            $reader->close();
            unlink($file);
        }
    }
}
