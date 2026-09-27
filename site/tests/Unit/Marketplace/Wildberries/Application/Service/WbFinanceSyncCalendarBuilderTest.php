<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Wildberries\Application\Service;

use App\Marketplace\Wildberries\Application\Service\WbFinanceSyncCalendarBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WbFinanceSyncCalendarBuilderTest extends TestCase
{
    private WbFinanceSyncCalendarBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new WbFinanceSyncCalendarBuilder();
    }

    public function testLaysOutMonthByWeekdayRowsAndWeekColumns(): void
    {
        // 01.09.2026 — вторник, в месяце 30 дней → 5 недель.
        $calendar = $this->builder->build([], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        self::assertSame('Сентябрь 2026', $calendar['title']);
        self::assertSame(['1–6', '7–13', '14–20', '21–27', '28–30'], $calendar['weekLabels']);
        self::assertSame(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'], array_column($calendar['weekdays'], 'label'));

        $monday = $calendar['weekdays'][0]['cells'];
        self::assertCount(5, $monday);
        self::assertNull($monday[0]);
        self::assertSame(28, $monday[4]['day'] ?? null);
        self::assertSame(1, $calendar['weekdays'][1]['cells'][0]['day'] ?? null);
        self::assertNull($calendar['weekdays'][6]['cells'][4]);
    }

    public function testSingleDayWeekLabelHasNoRange(): void
    {
        // 01.02.2026 — воскресенье: первая неделя из одного дня.
        $calendar = $this->builder->build([], new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame(['1', '2–8', '9–15', '16–22', '23–28'], $calendar['weekLabels']);
    }

    public function testMapsSyncStatusesToCellStates(): void
    {
        $rows = [
            $this->row('2026-09-01', 'success'),
            $this->row('2026-09-02', 'processing'),
            $this->row('2026-09-03', 'conflict'),
            $this->row('2026-09-04', 'failed_final'),
            $this->row('2026-09-07', 'auth_failed'),
            $this->row('2026-09-08', 'queued'),
            $this->row('2026-09-09', 'empty'),
        ];

        $cells = $this->cellsByDay($this->builder->build($rows, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-15 10:00')));

        self::assertSame(['ok', null], [$cells[1]['state'], $cells[1]['dot']]);
        self::assertSame(['partial', 'warn'], [$cells[2]['state'], $cells[2]['dot']]);
        self::assertSame(['partial', 'warn'], [$cells[3]['state'], $cells[3]['dot']]);
        self::assertSame(['err', 'err'], [$cells[4]['state'], $cells[4]['dot']]);
        self::assertSame(['err', 'err'], [$cells[7]['state'], $cells[7]['dot']]);
        self::assertSame('empty', $cells[8]['state']);
        self::assertSame('ok', $cells[9]['state'], 'EMPTY — загрузка прошла, у WB нет данных');
        self::assertSame('empty', $cells[10]['state'], 'день без строки статуса');
        self::assertSame('10.09.2026 · Нет загрузки', $cells[10]['title']);
        self::assertSame('empty', $cells[15]['state'], 'сегодняшний день ещё не будущий');
        self::assertSame('future', $cells[16]['state']);
        self::assertCount(30, $cells);
    }

    public function testTitleCarriesSyncDetailsAndError(): void
    {
        $row = $this->row('2026-09-04', 'failed', [
            'mode' => 'daily',
            'records_count' => 0,
            'attempts' => 3,
            'updated_at' => '2026-09-05 08:30:00',
            'last_error_class' => 'WbApiException',
            'last_error_message' => 'Too Many Requests',
            'last_error_status_code' => 429,
        ]);

        $cells = $this->cellsByDay($this->builder->build([$row], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-27')));

        self::assertSame(
            "04.09.2026 · Ошибка\nРежим: Ежедневная загрузка\nЗаписей: 0 · Попыток: 3\nОбновлено: 05.09.2026 08:30\nОшибка: HTTP 429 · WbApiException · Too Many Requests",
            $cells[4]['title'],
        );
    }

    public function testErrorDaysListDaysWithErrorNewestFirst(): void
    {
        $rows = [
            $this->row('2026-09-01', 'success'),
            $this->row('2026-09-02', 'failed_final', ['last_error_class' => 'WbApiException', 'last_error_message' => 'Bad request', 'last_error_status_code' => 400]),
            $this->row('2026-09-05', 'loading', ['last_error_message' => 'Too Many Requests', 'last_error_status_code' => 429]),
            $this->row('2026-09-03', 'conflict', ['last_error_message' => 'Rows changed']),
            $this->row('2026-09-04', 'processing'),
        ];

        $errorDays = $this->builder->build($rows, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-27'))['errorDays'];

        self::assertSame(
            ['2026-09-05 00:00:00', '2026-09-03 00:00:00', '2026-09-02 00:00:00'],
            array_column($errorDays, 'business_date'),
        );
        self::assertSame(['Загрузка', 'Конфликт', 'Финальная ошибка'], array_column($errorDays, 'status_label'));
        self::assertSame('Bad request', $errorDays[2]['last_error_message']);
        self::assertSame(400, $errorDays[2]['last_error_status_code']);
    }

    public function testErrorDaysEmptyWhenMonthIsClean(): void
    {
        $calendar = $this->builder->build([$this->row('2026-09-01', 'success')], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame([], $calendar['errorDays']);
    }

    public function testLongErrorMessageIsTruncated(): void
    {
        $row = $this->row('2026-09-04', 'failed', ['last_error_message' => str_repeat('x', 1000)]);

        $cells = $this->cellsByDay($this->builder->build([$row], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-27')));

        self::assertStringEndsWith('Ошибка: '.str_repeat('x', 299).'…', $cells[4]['title']);
    }

    public function testSixWeekMonth(): void
    {
        // 01.03.2026 — воскресенье, 31 день → 6 недель.
        $calendar = $this->builder->build([], new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame(['1', '2–8', '9–15', '16–22', '23–29', '30–31'], $calendar['weekLabels']);
        self::assertSame(31, $calendar['weekdays'][1]['cells'][5]['day'] ?? null);
        self::assertCount(31, $this->cellsByDay($calendar));
    }

    public function testDecemberNavigatesToNextYear(): void
    {
        $calendar = $this->builder->build([], new \DateTimeImmutable('2025-12-01'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame('Декабрь 2025', $calendar['title']);
        self::assertSame('2025-11', $calendar['prevMonth']);
        self::assertSame('2026-01', $calendar['nextMonth']);
    }

    public function testNextMonthIsHiddenForCurrentMonthOnly(): void
    {
        $today = new \DateTimeImmutable('2026-09-27');

        $current = $this->builder->build([], new \DateTimeImmutable('2026-09-01'), $today);
        self::assertSame('2026-08', $current['prevMonth']);
        self::assertNull($current['nextMonth']);

        $january = $this->builder->build([], new \DateTimeImmutable('2026-01-01'), $today);
        self::assertSame('2025-12', $january['prevMonth']);
        self::assertSame('2026-02', $january['nextMonth']);
    }

    #[DataProvider('monthValues')]
    public function testResolveMonth(string $value, string $expected): void
    {
        $month = $this->builder->resolveMonth($value, new \DateTimeImmutable('2026-09-27 15:00'));

        self::assertSame($expected, $month->format('Y-m-d H:i'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function monthValues(): iterable
    {
        yield 'пусто → текущий' => ['', '2026-09-01 00:00'];
        yield 'прошлый месяц' => ['2026-01', '2026-01-01 00:00'];
        yield 'текущий месяц' => ['2026-09', '2026-09-01 00:00'];
        yield 'будущий → текущий' => ['2026-10', '2026-09-01 00:00'];
        yield 'месяц 13 → текущий' => ['2026-13', '2026-09-01 00:00'];
        yield 'мусор → текущий' => ['2026-01-05', '2026-09-01 00:00'];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function row(string $date, string $status, array $extra = []): array
    {
        return array_merge([
            'business_date' => $date.' 00:00:00',
            'status' => $status,
            'mode' => null,
            'records_count' => 10,
            'attempts' => 1,
            'updated_at' => $date.' 12:00:00',
            'last_error_class' => null,
            'last_error_message' => null,
            'last_error_status_code' => null,
        ], $extra);
    }

    /**
     * @param array{weekdays: list<array{label: string, cells: list<?array{day: int, state: string, dot: ?string, title: string}>}>} $calendar
     *
     * @return array<int, array{day: int, state: string, dot: ?string, title: string}>
     */
    private function cellsByDay(array $calendar): array
    {
        $cells = [];
        foreach ($calendar['weekdays'] as $weekday) {
            foreach ($weekday['cells'] as $cell) {
                if (null !== $cell) {
                    $cells[$cell['day']] = $cell;
                }
            }
        }
        ksort($cells);

        return $cells;
    }
}
