<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Infrastructure\Export;

use App\MarketplaceAnalytics\Infrastructure\Export\UnitExtendedExportRequest;
use App\MarketplaceAnalytics\Infrastructure\Export\UnitExtendedXlsxExporter;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

final class UnitExtendedXlsxExporterTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private ?string $tempFile = null;

    protected function tearDown(): void
    {
        if (null !== $this->tempFile && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }

        $this->tempFile = null;
    }

    public function testExportWritesAllRowsAndTotals(): void
    {
        $items = [
            [
                'listingId' => 'l-1',
                'title' => 'Товар А',
                'sku' => 'SKU-A',
                'marketplace' => 'ozon',
                'revenue' => 1000.0,
                'quantity' => 5,
                'returnsTotal' => 50.0,
                'costPriceTotal' => 400.0,
                'costPriceUnit' => 80.0,
                'commission' => 100.0,
                'logistics' => 50.0,
                'otherCosts' => 20.0,
                'totalCosts' => 170.0,
                'profit' => 380.0,
                'marginPercent' => 38.0,
                'roiPercent' => 95.0,
                'otherCostsBreakdown' => [],
                'allCostsBreakdown' => [],
            ],
            [
                'listingId' => 'l-2',
                'title' => 'Товар Б',
                'sku' => 'SKU-B',
                'marketplace' => 'ozon',
                'revenue' => 500.0,
                'quantity' => 2,
                'returnsTotal' => 0.0,
                'costPriceTotal' => 200.0,
                'costPriceUnit' => 100.0,
                'commission' => 60.0,
                'logistics' => 30.0,
                'otherCosts' => 10.0,
                'totalCosts' => 100.0,
                'profit' => 200.0,
                'marginPercent' => 40.0,
                'roiPercent' => 100.0,
                'otherCostsBreakdown' => [],
                'allCostsBreakdown' => [],
            ],
            [
                'listingId' => 'l-3',
                'title' => 'Товар В',
                'sku' => 'SKU-C',
                'marketplace' => 'wildberries',
                'revenue' => 0.0,
                'quantity' => 0,
                'returnsTotal' => 0.0,
                'costPriceTotal' => 0.0,
                'costPriceUnit' => 0.0,
                'commission' => 0.0,
                'logistics' => 0.0,
                'otherCosts' => 0.0,
                'totalCosts' => 0.0,
                'profit' => 0.0,
                'marginPercent' => null,
                'roiPercent' => null,
                'otherCostsBreakdown' => [],
                'allCostsBreakdown' => [],
            ],
        ];

        $totals = [
            'revenue' => 1500.0,
            'quantity' => 7,
            'returnsTotal' => 50.0,
            'costPriceTotal' => 600.0,
            'commission' => 160.0,
            'logistics' => 80.0,
            'otherCosts' => 30.0,
            'totalCosts' => 270.0,
            'profit' => 580.0,
            'marginPercent' => 38.7,
            'roiPercent' => 96.7,
        ];

        $query = $this->createMock(UnitExtendedQuery::class);
        $query
            ->expects(self::once())
            ->method('execute')
            ->with(self::COMPANY_ID, 'ozon', '2026-01-01', '2026-01-31')
            ->willReturn(['items' => $items, 'totals' => $totals]);

        $exporter = new UnitExtendedXlsxExporter($query);
        $request = new UnitExtendedExportRequest(
            companyId: self::COMPANY_ID,
            marketplace: 'ozon',
            periodFrom: '2026-01-01',
            periodTo: '2026-01-31',
        );

        $this->tempFile = tempnam(sys_get_temp_dir(), 'unit_extended_').'.xlsx';

        $exporter->export($request, $this->tempFile);

        self::assertFileExists($this->tempFile);
        self::assertGreaterThan(0, filesize($this->tempFile));

        $rows = $this->readXlsxRows($this->tempFile);

        self::assertGreaterThanOrEqual(6, count($rows), 'Expected meta + empty + header + 3 data + totals');

        $headerRowIndex = null;
        foreach ($rows as $index => $row) {
            if (in_array('SKU', $row, true)) {
                $headerRowIndex = $index;

                break;
            }
        }

        self::assertNotNull($headerRowIndex, 'Header row with "SKU" not found');
        self::assertContains('Наименование', $rows[$headerRowIndex]);
        self::assertContains('ROI %', $rows[$headerRowIndex]);

        $dataRows = array_slice($rows, $headerRowIndex + 1, 3);
        self::assertCount(3, $dataRows, 'Expected exactly 3 data rows');

        $skus = array_map(static fn (array $row): string => (string) ($row[0] ?? ''), $dataRows);
        self::assertSame(['SKU-A', 'SKU-B', 'SKU-C'], $skus);

        $totalsRow = $rows[$headerRowIndex + 4];
        self::assertSame('ИТОГО', (string) $totalsRow[0]);
    }

    /**
     * @return list<list<string>>
     */
    private function readXlsxRows(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(
                    static fn ($cell): string => (string) $cell->getValue(),
                    $row->getCells(),
                );
                $rows[] = $cells;
            }

            break;
        }

        $reader->close();

        return $rows;
    }
}
