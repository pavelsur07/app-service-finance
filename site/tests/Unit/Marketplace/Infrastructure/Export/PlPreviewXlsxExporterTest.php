<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Export;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\Marketplace\Application\DTO\MarketplacePlPreviewColumn;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use App\Marketplace\Application\DTO\PlPreviewAdReconciliationDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewColumnKind;
use App\Marketplace\Enum\PlPreviewColumnMode;
use App\Marketplace\Infrastructure\Export\PlPreviewXlsxExporter;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

final class PlPreviewXlsxExporterTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'pl_preview_test_').'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testProjectModeSheetHeadersAndAdsNote(): void
    {
        $sheets = $this->export($this->report(PlPreviewColumnMode::PROJECTS, [
            new MarketplacePlPreviewColumn('project:p1', 'Маркетплейсы', PlPreviewColumnKind::PROJECT),
            new MarketplacePlPreviewColumn('project:p2', 'Ozon', PlPreviewColumnKind::PROJECT, 2),
            new MarketplacePlPreviewColumn('no_project', 'Без проекта', PlPreviewColumnKind::NO_PROJECT),
            new MarketplacePlPreviewColumn('total', 'Итого', PlPreviewColumnKind::TOTAL),
        ]));

        self::assertArrayHasKey('ОПиУ по проектам', $sheets);
        self::assertContains(['Статья', 'Маркетплейсы', '— Ozon', 'Без проекта', 'Итого'], $sheets['ОПиУ по проектам']);
        self::assertContains(['Реклама: учёт и статистика (в колонках проектов — по учёту)'], $sheets['Сверка']);
    }

    public function testTagModeKeepsSheetName(): void
    {
        $sheets = $this->export($this->report(PlPreviewColumnMode::TAGS, [
            new MarketplacePlPreviewColumn('tag:t1', 'Зима', PlPreviewColumnKind::TAG),
            new MarketplacePlPreviewColumn('common', 'Общие / без тега', PlPreviewColumnKind::COMMON),
            new MarketplacePlPreviewColumn('total', 'Итого', PlPreviewColumnKind::TOTAL),
        ]));

        self::assertArrayHasKey('ОПиУ по тегам', $sheets);
        self::assertContains(['Реклама: учёт и статистика (в колонках тегов — по статистике)'], $sheets['Сверка']);
    }

    /**
     * @param list<MarketplacePlPreviewColumn> $columns
     */
    private function report(PlPreviewColumnMode $mode, array $columns): MarketplacePlPreviewReport
    {
        return new MarketplacePlPreviewReport(
            from: new \DateTimeImmutable('2026-01-01'),
            to: new \DateTimeImmutable('2026-01-31'),
            marketplace: null,
            columns: $columns,
            rows: [new PlPreviewRow('rev', 'REV', 'Выручка', 1, PLCategoryType::LEAF_INPUT, PLValueFormat::MONEY, ['total' => 100.0])],
            tagsOverlap: false,
            unplaced: [],
            costCoverage: [],
            costTotal: '0.00',
            costReconciliationDelta: '0.00',
            salesCoverage: [],
            warnings: [],
            adReconciliation: [new PlPreviewAdReconciliationDTO(MarketplaceType::WILDBERRIES, '-100.00', '90.00', '80.00', '10.00', '-10.00')],
            mode: $mode,
        );
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function export(MarketplacePlPreviewReport $report): array
    {
        (new PlPreviewXlsxExporter())->export($report, false, $this->file);

        $reader = new Reader();
        $reader->open($this->file);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $sheets;
    }
}
