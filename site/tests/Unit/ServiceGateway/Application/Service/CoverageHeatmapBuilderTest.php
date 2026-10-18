<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\DTO\CoverageHeatmapCell;
use App\ServiceGateway\Application\DTO\ServiceCoverageMonth;
use App\ServiceGateway\Application\DTO\ServiceCoverageMonthCell;
use App\ServiceGateway\Application\DTO\ServiceCoverageSnapshotView;
use App\ServiceGateway\Application\DTO\ServiceCoverageStatusCounts;
use App\ServiceGateway\Application\Service\CoverageHeatmapBuilder;
use PHPUnit\Framework\TestCase;

final class CoverageHeatmapBuilderTest extends TestCase
{
    private const BY_DAY = 'ozon.finance.accrual-by-day';

    private static function now(string $at = '2026-10-18 09:10:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('Europe/Moscow'));
    }

    private static function cell(string $resource, string $period, int $expected, int $done, int $running = 0, int $retryable = 0, int $permanent = 0, ?\DateTimeImmutable $lastSuccess = null): ServiceCoverageMonthCell
    {
        return new ServiceCoverageMonthCell($resource, $period, $expected, new ServiceCoverageStatusCounts($done, $running, 0, 0, $retryable, $permanent), $lastSuccess);
    }

    /**
     * @param list<ServiceCoverageMonthCell> $cells
     */
    private static function month(string $month, array $cells, ?\DateTimeImmutable $refreshedAt = null): ServiceCoverageMonth
    {
        $snapshot = new ServiceCoverageSnapshotView(self::now(), $refreshedAt ?? self::now('2026-10-18 09:05:00'), new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-10-31'), 4);

        return new ServiceCoverageMonth('ozon-fetcher', $month, $snapshot, $cells);
    }

    public function testDailyRowHasCellPerDayWithAllStates(): void
    {
        $heatmap = (new CoverageHeatmapBuilder())->build(self::month('2026-10', [
            self::cell(self::BY_DAY, '2026-10-01', 4, 4, lastSuccess: self::now('2026-10-02 00:05:00')),
            self::cell(self::BY_DAY, '2026-10-02', 4, 3, running: 1),
            self::cell(self::BY_DAY, '2026-10-03', 4, 3, permanent: 1),
            self::cell(self::BY_DAY, '2026-10-04', 4, 2, retryable: 1),
        ]), self::now());

        self::assertCount(31, $heatmap->days);
        self::assertTrue($heatmap->days[17]->today, '18-е — сегодня');
        self::assertTrue($heatmap->days[2]->weekend, '03.10.2026 — суббота');
        self::assertFalse($heatmap->days[0]->weekend);

        $row = $heatmap->rows[0];
        self::assertSame('Начисления по дням', $row->title);
        self::assertTrue($row->perDay);
        self::assertCount(31, $row->cells);

        $states = array_map(static fn (CoverageHeatmapCell $c): string => $c->state, $row->cells);
        self::assertSame([CoverageHeatmapCell::OK, CoverageHeatmapCell::PARTIAL, CoverageHeatmapCell::ERR, CoverageHeatmapCell::PARTIAL, CoverageHeatmapCell::EMPTY], \array_slice($states, 0, 5));
        self::assertSame(CoverageHeatmapCell::EMPTY, $states[16], '17-е без задач — пропуск');
        self::assertSame(CoverageHeatmapCell::FUTURE, $states[17], 'сегодня без задач — ещё не наступило');
        self::assertSame(CoverageHeatmapCell::FUTURE, $states[30]);
        self::assertSame(1, $row->errCount);
        self::assertSame(2, $row->partialCount);

        self::assertSame('4/4', $row->cells[0]->label);
        self::assertSame('3/4', $row->cells[2]->label);
        self::assertSame('—', $row->cells[4]->label);
        self::assertSame('', $row->cells[30]->label);
        self::assertSame("Начисления по дням · 01.10.2026\nЗагружено: 4 из 4 кабинетов\nПоследняя успешная: 02.10.2026 00:05", $row->cells[0]->hint);
        self::assertStringContainsString('ошибка: 1', $row->cells[2]->hint);
        self::assertStringContainsString('повторится: 1 · без задачи: 1', $row->cells[3]->hint);
        self::assertStringContainsString('Успешной загрузки ещё не было', $row->cells[1]->hint);
    }

    public function testMonthlyAndReferenceRowsHaveSingleCell(): void
    {
        $heatmap = (new CoverageHeatmapBuilder())->build(self::month('2026-09', [
            self::cell('ozon.finance.realization', '2026-09', 4, 4),
            self::cell('ozon.finance.accrual-types', 'ref', 4, 3, permanent: 1),
        ]), self::now());

        $rows = [];
        foreach ($heatmap->rows as $row) {
            $rows[$row->resourceType] = $row;
        }
        self::assertSame([self::BY_DAY, 'ozon.finance.realization', 'ozon.finance.mutual-settlement', 'ozon.finance.accrual-types'], array_keys($rows), 'все известные отчёты — всегда, в постоянном порядке');

        self::assertFalse($rows['ozon.finance.realization']->perDay);
        self::assertCount(1, $rows['ozon.finance.realization']->cells);
        self::assertSame(CoverageHeatmapCell::OK, $rows['ozon.finance.realization']->cells[0]->state);
        self::assertSame(CoverageHeatmapCell::EMPTY, $rows['ozon.finance.mutual-settlement']->cells[0]->state, 'прошлый месяц без задачи — пропуск');
        self::assertSame(CoverageHeatmapCell::ERR, $rows['ozon.finance.accrual-types']->cells[0]->state);
        self::assertStringStartsWith('Типы начислений (справочник) · справочник', $rows['ozon.finance.accrual-types']->cells[0]->hint);
        self::assertSame(CoverageHeatmapCell::EMPTY, $rows[self::BY_DAY]->cells[29]->state, 'прошлый месяц: дни без задач — пропуск, не будущее');
    }

    public function testMonthlyReportOfCurrentMonthIsFuture(): void
    {
        $heatmap = (new CoverageHeatmapBuilder())->build(self::month('2026-10', []), self::now());

        self::assertSame(CoverageHeatmapCell::FUTURE, $heatmap->rows[1]->cells[0]->state, 'реализацию за текущий месяц Ozon ещё не формирует');
        self::assertSame(CoverageHeatmapCell::EMPTY, $heatmap->rows[3]->cells[0]->state, 'справочник от месяца не зависит');
        self::assertSame(0, $heatmap->coveragePercent, 'наступившие дни без задач — пропуски, не «нет данных»');
    }

    public function testUnknownResourceIsShownNotDropped(): void
    {
        $heatmap = (new CoverageHeatmapBuilder())->build(self::month('2026-10', [
            self::cell('ozon.finance.new-report', '2026-10-05', 4, 4),
        ]), self::now());

        $last = $heatmap->rows[4];
        self::assertSame('ozon.finance.new-report', $last->title);
        self::assertTrue($last->perDay);
        self::assertSame(CoverageHeatmapCell::OK, $last->cells[4]->state);
    }

    public function testCoveragePercentAndStaleBoundary(): void
    {
        $cells = [self::cell(self::BY_DAY, '2026-10-01', 4, 4), self::cell(self::BY_DAY, '2026-10-02', 4, 2), self::cell(self::BY_DAY, '2026-10-03', 4, 5)];
        $builder = new CoverageHeatmapBuilder();

        $fresh = $builder->build(self::month('2026-10', $cells, self::now('2026-10-18 08:40:00')), self::now());
        // Наступившие периоды: 17 дней начислений (3 с задачами, 14 пропусков по 4 кабинета),
        // реализация и взаиморасчёты за текущий месяц — ещё не наступили, справочник без задач — 4.
        // done: 4 + 2 + 4 (сверх ожидаемого не завышает) = 10; ожидаемо: 17 × 4 + 4 = 72.
        self::assertSame(13, $fresh->coveragePercent);
        self::assertFalse($fresh->stale, 'ровно 30 минут — ещё не устарело');
        self::assertSame(4, $fresh->cabinetsTotal);
        self::assertFalse($fresh->isEmpty());

        $stale = $builder->build(self::month('2026-10', $cells, self::now('2026-10-18 08:39:59')), self::now());
        self::assertTrue($stale->stale);
    }

    public function testMissingPastPeriodsLowerCoverage(): void
    {
        $full = [
            self::cell(self::BY_DAY, '2026-09-01', 4, 4),
            self::cell('ozon.finance.realization', '2026-09', 4, 4),
            self::cell('ozon.finance.mutual-settlement', '2026-09', 4, 4),
            self::cell('ozon.finance.accrual-types', 'ref', 4, 4),
        ];

        $heatmap = (new CoverageHeatmapBuilder())->build(self::month('2026-09', $full), self::now());

        // Сентябрь прошёл целиком: 30 дней × 4 + три месячные ячейки × 4 = 132 ожидаемо, загружено 16.
        self::assertSame(12, $heatmap->coveragePercent, 'один загруженный день не даёт 100 % при 29 пропусках');
    }

    public function testEmptyReadModel(): void
    {
        $heatmap = (new CoverageHeatmapBuilder())->build(new ServiceCoverageMonth('ozon-fetcher', '2026-10', null, []), self::now());

        self::assertTrue($heatmap->isEmpty());
        self::assertFalse($heatmap->stale);
        self::assertNull($heatmap->cabinetsTotal);
    }
}
