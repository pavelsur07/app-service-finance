<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\DTO;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\Marketplace\Application\DTO\MarketplacePlPreviewReport;
use PHPUnit\Framework\TestCase;

final class MarketplacePlPreviewReportTest extends TestCase
{
    public function testEmptyRowDependsOnDisplayedPrecisionOfItsFormat(): void
    {
        $report = $this->report([
            $this->row('MARGIN_PCT', PLValueFormat::PERCENT, 0.003),  // 0,3 % — видно
            $this->row('TINY_PCT', PLValueFormat::PERCENT, 0.0004),   // 0,0 % — пусто
            $this->row('RATIO', PLValueFormat::RATIO, 0.0003),        // 0,0003 — видно
            $this->row('TINY_RATIO', PLValueFormat::RATIO, 0.00004),  // 0,0000 — пусто
            $this->row('REV', PLValueFormat::MONEY, -0.005),          // −0,01 — видно
            $this->row('DUST', PLValueFormat::MONEY, 0.0045),         // 0,00 — пусто
            $this->row('ZERO', PLValueFormat::MONEY, 0.0),
        ]);

        self::assertSame(
            ['MARGIN_PCT', 'RATIO', 'REV'],
            array_map(static fn (PlPreviewRow $row): ?string => $row->code, $report->visibleRows(false)),
        );
        self::assertCount(7, $report->visibleRows(true));
    }

    /**
     * @param list<PlPreviewRow> $rows
     */
    private function report(array $rows): MarketplacePlPreviewReport
    {
        return new MarketplacePlPreviewReport(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            null,
            [],
            $rows,
            false,
            [],
            [],
            '0.00',
            '0.00',
            [],
            [],
        );
    }

    private function row(string $code, PLValueFormat $format, float $total): PlPreviewRow
    {
        return new PlPreviewRow($code, $code, $code, 1, PLCategoryType::KPI, $format, ['common' => 0.0, 'total' => $total]);
    }
}
