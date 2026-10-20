<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Export;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\Marketplace\Application\DTO\MarketplacePlPreviewColumn;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Application\DTO\PlPreviewCostCoverageDTO;
use App\Marketplace\Application\DTO\PlPreviewRowsExport;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewColumnKind;
use App\Marketplace\Enum\PlPreviewCostStatus;
use App\Marketplace\Infrastructure\Export\PlPreviewRowsXlsxExporter;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

final class PlPreviewRowsXlsxExporterTest extends TestCase
{
    private const string TAG_ID = '0199f000-0000-7000-8000-000000000001';
    private const string REV_ID = '0199f000-0000-7000-8000-0000000000a1';
    private const string COST_ID = '0199f000-0000-7000-8000-0000000000a2';
    private const string MARGIN_ID = '0199f000-0000-7000-8000-0000000000a3';

    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'pl_rows_test_').'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testDataSheetIsFullDeterministicGridOfTypedCells(): void
    {
        $sheets = $this->export($this->twoChannels());

        self::assertSame(['Данные', 'Паспорт'], array_slice(array_keys($sheets), 0, 2));
        $data = $sheets['Данные'];
        self::assertSame(
            ['period_from', 'period_to', 'period_key', 'iso_week', 'marketplace', 'column_kind', 'tag_id', 'tag_name', 'pl_category_id', 'pl_code', 'row_order', 'row_level', 'row_name', 'category_type', 'value_format', 'value', 'generated_at', 'format_ver'],
            self::values($data[0]),
        );

        $rows = array_slice($data, 1);
        // 2 канала × 3 колонки × 3 строки дерева, нулевые тоже.
        self::assertCount(18, $rows);

        $keys = array_map(static fn (array $r): string => implode('|', [self::v($r[2]), self::v($r[4]), self::v($r[5]), self::v($r[6]), self::v($r[8])]), $rows);
        self::assertSame($keys, array_unique($keys));
        self::assertSame(
            ['ozon|tag|'.self::TAG_ID, 'ozon|common|', 'ozon|total|', 'wildberries|tag|'.self::TAG_ID, 'wildberries|common|', 'wildberries|total|'],
            array_values(array_unique(array_map(static fn (array $r): string => self::v($r[4]).'|'.self::v($r[5]).'|'.self::v($r[6]), $rows))),
        );

        $first = $rows[0];
        self::assertInstanceOf(DateTimeCell::class, $first[0]);
        self::assertSame('2026-10-12', self::v($first[0]));
        self::assertSame('2026-10-18', self::v($first[1]));
        self::assertSame('2026-10-12_2026-10-18', self::v($first[2]));
        self::assertSame('2026-W42', self::v($first[3]));
        self::assertSame('=Склад', self::v($first[7]));
        self::assertSame('=Выручка', self::v($first[12]));
        // Reader сам делает FormulaCell из любой строки на «=» — проверяем то, что записано в файл.
        $sheetXml = $this->sheetXml(1);
        self::assertStringNotContainsString('<f>', $sheetXml);
        self::assertStringContainsString('t="inlineStr"><is><t>=Выручка</t>', $sheetXml);
        self::assertInstanceOf(StringCell::class, $first[13]);
        self::assertSame([1, 1], [self::v($first[10]), self::v($first[11])]);
        self::assertInstanceOf(NumericCell::class, $first[15]);
        self::assertEqualsWithDelta(100.13, self::v($first[15]), 1e-9);
        self::assertInstanceOf(DateTimeCell::class, $first[16]);
        self::assertSame('2026-10-20 09:15:00', self::v($first[16]));
        self::assertSame('1.0', self::v($first[17]));

        $costNoCode = $rows[1];
        self::assertSame(['', 2, 2, 'LEAF_INPUT', 'MONEY'], [self::v($costNoCode[9]), self::v($costNoCode[10]), self::v($costNoCode[11]), self::v($costNoCode[13]), self::v($costNoCode[14])]);
        self::assertSame(0.0, (float) self::v($rows[4][15]), 'нулевая строка присутствует');

        $margin = $rows[2];
        self::assertSame(['MARGIN', 'KPI', 'PERCENT'], [self::v($margin[9]), self::v($margin[13]), self::v($margin[14])]);
        self::assertEqualsWithDelta(0.27731234, self::v($margin[15]), 1e-12, 'доля без округления');
    }

    public function testIsoWeekEmptyWhenPeriodIsNotMondayToSunday(): void
    {
        $export = $this->twoChannels(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-04'));

        $rows = $this->export($export)['Данные'];

        self::assertSame('', self::v($rows[1][3]));
    }

    public function testPassportCountsAndChecks(): void
    {
        $sheets = $this->export($this->twoChannels());

        self::assertSame(['key', 'value', 'label'], self::values($sheets['Паспорт'][0]));
        $passport = [];
        foreach (array_slice($sheets['Паспорт'], 1) as $row) {
            $passport[self::v($row[0])] = $row[1];
        }

        self::assertSame(\count($sheets['Данные']) - 1, self::v($passport['rows_count']));
        self::assertSame(9, self::v($passport['rows_count_ozon']));
        self::assertSame(9, self::v($passport['rows_count_wildberries']));
        self::assertSame('ozon;wildberries', self::v($passport['marketplaces']));
        self::assertSame(self::TAG_ID.'==Склад', self::v($passport['tags']));
        self::assertSame(1, self::v($passport['tags_overlap']));
        self::assertSame('1.0', self::v($passport['format_ver']));
        self::assertSame('2026-W42', self::v($passport['iso_week']));
        self::assertEqualsWithDelta(-120.5, self::v($passport['cost_total_wildberries']), 1e-9);
        self::assertEqualsWithDelta(-100.0, self::v($passport['cost_mapped_wildberries']), 1e-9);
        self::assertEqualsWithDelta(-20.5, self::v($passport['cost_unmapped_wildberries']), 1e-9);
        self::assertEqualsWithDelta(0.0, (float) self::v($passport['cost_excluded_wildberries']), 1e-9);
        self::assertEqualsWithDelta(0.0, (float) self::v($passport['cost_delta_wildberries']), 1e-9);

        // REV: каналы 100.13 + 200 = 300.13, общий 300.10; COST без кода — по id; проценты не сверяются.
        self::assertEqualsWithDelta(0.03, self::v($passport['check_channels_vs_all_REV']), 1e-9);
        self::assertEqualsWithDelta(0.0, (float) self::v($passport['check_channels_vs_all_'.self::COST_ID]), 1e-9);
        self::assertArrayNotHasKey('check_channels_vs_all_MARGIN', $passport);
        self::assertArrayHasKey('field_value', $passport);
    }

    public function testNoChannelChecksForExplicitChannel(): void
    {
        $export = $this->twoChannels();
        $sheets = $this->export(new PlPreviewRowsExport($export->from, $export->to, [$export->channels[0]], null));

        $keys = array_map(static fn (array $row): string => self::v($row[0]), $sheets['Паспорт']);
        self::assertSame([], array_values(array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'check_'))));
        self::assertCount(10, $sheets['Данные']);
    }

    private function twoChannels(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): PlPreviewRowsExport
    {
        $from ??= new \DateTimeImmutable('2026-10-12');
        $to ??= new \DateTimeImmutable('2026-10-18');

        $ozon = $this->report($from, $to, MarketplaceType::OZON, ['tag:'.self::TAG_ID => 100.134, 'common' => 0.0, 'total' => 100.13], 0.27731234, true, []);
        $wb = $this->report($from, $to, MarketplaceType::WILDBERRIES, ['tag:'.self::TAG_ID => 150.0, 'common' => 50.0, 'total' => 200.0], 0.5, false, [
            new PlPreviewCostCoverageDTO(MarketplaceType::WILDBERRIES, null, 'wb_log', 'Логистика', PlPreviewCostStatus::MAPPED, 3, '-100.00'),
            new PlPreviewCostCoverageDTO(MarketplaceType::WILDBERRIES, null, 'wb_x', 'Прочее', PlPreviewCostStatus::UNMAPPED, 1, '-20.50'),
        ], '-120.50');
        $all = $this->report($from, $to, null, ['tag:'.self::TAG_ID => 250.13, 'common' => 50.0, 'total' => 300.10], 0.4, true, []);

        return new PlPreviewRowsExport($from, $to, [$ozon, $wb], $all);
    }

    /**
     * @param array<string, float> $revenue
     * @param list<PlPreviewCostCoverageDTO> $costs
     */
    private function report(\DateTimeImmutable $from, \DateTimeImmutable $to, ?MarketplaceType $marketplace, array $revenue, float $margin, bool $overlap, array $costs, string $costTotal = '0.00'): MarketplacePlPreviewReport
    {
        return new MarketplacePlPreviewReport(
            from: $from,
            to: $to,
            marketplace: $marketplace,
            columns: [
                new MarketplacePlPreviewColumn('tag:'.self::TAG_ID, '=Склад', PlPreviewColumnKind::TAG),
                new MarketplacePlPreviewColumn('common', 'Общие / без тега', PlPreviewColumnKind::COMMON),
                new MarketplacePlPreviewColumn('total', 'Итого', PlPreviewColumnKind::TOTAL),
            ],
            rows: [
                new PlPreviewRow(self::REV_ID, 'REV', '=Выручка', 1, PLCategoryType::LEAF_INPUT, PLValueFormat::MONEY, $revenue),
                new PlPreviewRow(self::COST_ID, null, 'Затраты', 2, PLCategoryType::LEAF_INPUT, PLValueFormat::MONEY, []),
                new PlPreviewRow(self::MARGIN_ID, 'MARGIN', 'Маржа', 1, PLCategoryType::KPI, PLValueFormat::PERCENT, ['tag:'.self::TAG_ID => $margin, 'common' => 0.0, 'total' => $margin]),
            ],
            tagsOverlap: $overlap,
            unplaced: [],
            costCoverage: $costs,
            costTotal: $costTotal,
            costReconciliationDelta: '0.00',
            salesCoverage: [],
            warnings: [],
        );
    }

    /**
     * @return array<string, list<list<Cell>>>
     */
    private function export(PlPreviewRowsExport $export): array
    {
        (new PlPreviewRowsXlsxExporter())->export($export, new \DateTimeImmutable('2026-10-20 09:15:00'), $this->file);

        $reader = new Reader();
        $reader->open($this->file);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_values($row->getCells());
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $sheets;
    }

    private function sheetXml(int $index): string
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->file));
        $xml = $zip->getFromName(sprintf('xl/worksheets/sheet%d.xml', $index));
        $zip->close();
        self::assertIsString($xml);

        return $xml;
    }

    /**
     * @param list<Cell> $cells
     *
     * @return list<mixed>
     */
    private static function values(array $cells): array
    {
        return array_map(self::v(...), $cells);
    }

    private static function v(Cell $cell): mixed
    {
        $value = $cell->getValue();
        if ($value instanceof \DateTimeInterface) {
            return '00:00:00' === $value->format('H:i:s') ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
