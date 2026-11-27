<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceEconomics;

use App\MarketplaceEconomics\Application\DTO\SkuEconomicsReport;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsRow;
use App\MarketplaceEconomics\Application\DTO\WeeklyEconomicsSummary;
use App\MarketplaceEconomics\Domain\SkuMetrics;
use App\Shared\Domain\AbcClass;
use PHPUnit\Framework\TestCase;

final class WeeklyEconomicsSummaryTest extends TestCase
{
    public function testTopChangesLossesAndHighDrr(): void
    {
        $rows = [];
        // md2 текущей недели и прошлой; реклама — для ДРР
        foreach ([['A', 500, 100, 0], ['B', -300, 200, 0], ['C', 100, 100, -30_00], ['D', -50, -50, 0], ['E', 0, 0, 0]] as [$sku, $now, $before, $ads]) {
            $rows[] = new SkuEconomicsRow($sku, $sku, 'Товар '.$sku, null, self::metrics(100_00, $now * 100, $ads), self::metrics(100_00, $before * 100, 0), AbcClass::A, AbcClass::A);
        }
        $total = self::metrics(500_00, 250_00, -30_00);
        $report = new SkuEconomicsReport(new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-08'), new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-11-01'),
            $rows, new SkuMetrics(), $total, self::metrics(400_00, 350_00, 0), ['c1'], 7, 0);

        $summary = WeeklyEconomicsSummary::fromReport($report, 3);

        self::assertSame(['A'], array_map(static fn (array $c): string => $c['sku'], $summary->growth), 'рост только с положительной разницей');
        self::assertSame(400_00, $summary->growth[0]['delta']);
        self::assertSame(['B'], array_map(static fn (array $c): string => $c['sku'], $summary->fall));
        self::assertSame(2, $summary->losses, 'B и D с отрицательной СМ2');
        self::assertSame('B', $summary->worst[0]['sku']);
        self::assertSame(1, $summary->highDrr, 'C: 30 %');
        self::assertSame(-100_00, $summary->md2Delta);
    }

    public function testChannelOfSkuAndChannelTotals(): void
    {
        $row = new SkuEconomicsRow('L1', '123', 'Платье', null, self::metrics(100_00, 50_00, 0), self::metrics(0, 0, 0), AbcClass::A, AbcClass::A, 'wildberries');
        $report = new SkuEconomicsReport(new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-08'), new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-11-01'),
            [$row], new SkuMetrics(), $row->current, new SkuMetrics(), ['c1', 'c2'], 7, 0, ['ozon', 'wildberries']);
        $byChannel = ['ozon' => new SkuMetrics(), 'wildberries' => $row->current];

        $summary = WeeklyEconomicsSummary::fromReport($report, 5, $byChannel);

        self::assertSame('wildberries', $summary->growth[0]['channel']);
        self::assertSame($byChannel, $summary->byChannel);
    }

    public function testEmptyWeek(): void
    {
        $report = new SkuEconomicsReport(new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-08'), new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-11-01'),
            [], null, new SkuMetrics(), new SkuMetrics(), [], 7, 0);

        $summary = WeeklyEconomicsSummary::fromReport($report);

        self::assertSame([], $summary->growth);
        self::assertSame(0, $summary->losses);
        self::assertNull($summary->total->drr());
    }

    private static function metrics(int $revenue, int $md2, int $ads): SkuMetrics
    {
        $m = new SkuMetrics();
        $m->revenue = $revenue;
        $m->commission = $md2 - $revenue - $ads;
        $m->ads = $ads;

        return $m;
    }
}
