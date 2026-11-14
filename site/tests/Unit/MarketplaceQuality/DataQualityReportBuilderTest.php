<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceQuality;

use App\MarketplaceEconomics\Application\DTO\EconomicsDaySum;
use App\MarketplaceEconomics\Facade\MarketplaceEconomicsFacadeInterface;
use App\MarketplaceOperations\Application\DTO\CanonDaySum;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplaceOzon\Application\DTO\OzonDayState;
use App\MarketplaceOzon\Facade\MarketplaceOzonFacadeInterface;
use App\MarketplaceQuality\Application\DTO\QualityProblem;
use App\MarketplaceQuality\Application\Service\DataQualityReportBuilder;
use App\SourceFacts\Application\DTO\SourcePeriod;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;
use PHPUnit\Framework\TestCase;

final class DataQualityReportBuilderTest extends TestCase
{
    private const CO = '01900000-0000-7000-8000-000000000001';

    public function testAllLinksMatch(): void
    {
        $report = $this->build(
            periods: [['c1', '2026-11-09', 1], ['c1', '2026-11-10', 1]],
            translated: [['c1', '2026-11-09', 1, 1000, 1000, 0], ['c1', '2026-11-10', 1, 500, 500, 0]],
            canon: [['c1', '2026-11-09', 1000], ['c1', '2026-11-10', 500]],
            mart: [['c1', '2026-11-09', 1000, 0, 400], ['c1', '2026-11-10', 500, 0, 100]],
        );

        self::assertSame([], $report->problems);
        $c = $report->cabinets[0];
        self::assertSame(['2026-11-10', '2026-11-10', '2026-11-10', 0, 1500, 1500, 1500, 0.0], [$c->lastFactDay, $c->lastTranslatedDay, $c->lastMartDay, $c->untranslatedDays, $c->factsMinor, $c->canonMinor, $c->martMinor, $c->unallocatedPercent()]);
    }

    public function testEveryLinkReportsItsDay(): void
    {
        $report = $this->build(
            // 11.11 — новая ревизия не переведена; 12.11 вне периода — только последний день фактов
            periods: [['c1', '2026-11-09', 1], ['c1', '2026-11-10', 1], ['c1', '2026-11-11', 2], ['c1', '2026-11-12', 1]],
            translated: [['c1', '2026-11-09', 1, 1000, 900, 100], ['c1', '2026-11-10', 1, 500, 500, 0], ['c1', '2026-11-11', 1, 0, 0, 0]],
            canon: [['c1', '2026-11-09', 900], ['c1', '2026-11-10', 450]],
            mart: [['c1', '2026-11-09', 899, -50, 500]],
        );

        $problems = array_map(static fn (QualityProblem $p): array => [$p->date, $p->link, $p->diffMinor], $report->problems);
        self::assertSame([
            ['2026-11-11', QualityProblem::UNTRANSLATED, null],
            ['2026-11-10', QualityProblem::CANON_MART, -450],
            ['2026-11-10', QualityProblem::TRANSLATION_CANON, -50],
            ['2026-11-09', QualityProblem::CANON_MART, -1],
            ['2026-11-09', QualityProblem::QUARANTINE, 100],
        ], $problems);
        self::assertSame('2026-11-12', $report->cabinets[0]->lastFactDay);
        self::assertSame(10.0, $report->cabinets[0]->unallocatedPercent());
    }

    /**
     * @param list<array{string, string, int}> $periods
     * @param list<array{string, string, int, int, int, int}> $translated
     * @param list<array{string, string, int}> $canon
     * @param list<array{string, string, int, int, int}> $mart
     */
    private function build(array $periods, array $translated, array $canon, array $mart): \App\MarketplaceQuality\Application\DTO\DataQualityReport
    {
        $sf = $this->createStub(SourceFactsFacadeInterface::class);
        $sf->method('periods')->willReturn(array_map(static fn (array $p): SourcePeriod => new SourcePeriod(self::CO, ...$p), $periods));
        $ozon = $this->createStub(MarketplaceOzonFacadeInterface::class);
        $ozon->method('dayStates')->willReturn(array_map(static fn (array $d): OzonDayState => new OzonDayState($d[0], $d[1], $d[2], true, $d[3], $d[4], $d[5]), $translated));
        $ops = $this->createStub(MarketplaceOperationsFacadeInterface::class);
        $ops->method('daySums')->willReturn(array_map(static fn (array $s): CanonDaySum => new CanonDaySum(self::CO, ...$s), $canon));
        $me = $this->createStub(MarketplaceEconomicsFacadeInterface::class);
        $me->method('daySums')->willReturn(array_map(static fn (array $d): EconomicsDaySum => new EconomicsDaySum(...$d), $mart));

        return (new DataQualityReportBuilder($sf, $ozon, $ops, $me))->build(self::CO, new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2026-11-11'));
    }
}
