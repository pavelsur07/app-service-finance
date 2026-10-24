<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\DTO\CoverageHeatmap;
use App\ServiceGateway\Application\DTO\CoverageHeatmapCell;
use App\ServiceGateway\Application\DTO\CoverageHeatmapDay;
use App\ServiceGateway\Application\DTO\CoverageHeatmapRow;
use App\ServiceGateway\Application\DTO\ServiceCoverageMonth;
use App\ServiceGateway\Application\DTO\ServiceCoverageMonthCell;

/**
 * Витрина месяца → тепловая карта: строки — отчёты сервиса, столбцы — дни.
 * Месячные отчёты и справочник — одной ячейкой на месяц. Без БД и HTTP.
 *
 * Цвет ячейки:
 * - err — есть failed_permanent (нужен человек);
 * - ok — done = cabinets_expected;
 * - partial — загружено не всё, остальное в работе / в очереди / повторится;
 * - empty — задач нет;
 * - future — день ещё не наступил (или месячный отчёт текущего месяца ещё не
 *   формируется).
 */
final readonly class CoverageHeatmapBuilder
{
    public const STALE_AFTER_SECONDS = 30 * 60;

    private const STEP_DAY = 'day';
    private const STEP_MONTH = 'month';
    private const STEP_REF = 'ref';

    /** Известные отчёты ozon-fetcher в порядке показа: название и шаг строки. */
    private const RESOURCES = [
        'ozon.finance.accrual-by-day' => ['Начисления по дням', self::STEP_DAY],
        'ozon.finance.realization' => ['Реализация', self::STEP_MONTH],
        'ozon.finance.mutual-settlement' => ['Взаиморасчёты', self::STEP_MONTH],
        'ozon.finance.accrual-types' => ['Типы начислений (справочник)', self::STEP_REF],
    ];

    /**
     * Отчёты, которые включаются по кабинетам постепенно: строка — только при
     * задачах в месяце, дни до первой задачи — не пропуск (отчёт ещё не грузился).
     */
    private const OPTIONAL_RESOURCES = [
        'ozon.orders.fbo' => ['Заказы FBO', self::STEP_DAY],
        'ozon.orders.fbs' => ['Заказы FBS', self::STEP_DAY],
        'ozon.finance.accrual-postings' => ['Начисления по отправлениям', self::STEP_DAY],
        'ozon.products.list' => ['Каталог: список товаров', self::STEP_DAY],
        'ozon.products.info' => ['Каталог: подробности', self::STEP_DAY],
        'ozon.stocks' => ['Остатки: снимок 05:00', self::STEP_DAY],
        'ozon.stocks.warehouses' => ['Остатки по складам: снимок 05:00', self::STEP_DAY],
        'ozon.prices' => ['Цены: снимок 05:00', self::STEP_DAY],
        'ozon.ads.campaigns' => ['Реклама: кампании', self::STEP_DAY],
        'ozon.ads.expense' => ['Реклама: расход', self::STEP_DAY],
        'ozon.ads.daily' => ['Реклама: статистика по дням', self::STEP_DAY],
        'ozon.ads.statistics' => ['Реклама: отчёт по товарам', self::STEP_DAY],
        'ozon.ads.statistics.tail' => ['Реклама: отчёт, хвост 14 дней', self::STEP_DAY],
    ];

    private const STATUS_LABELS = [
        'running' => 'в работе',
        'pending' => 'в очереди',
        'notReady' => 'отчёт не готов',
        'failedRetryable' => 'повторится',
        'failedPermanent' => 'ошибка',
    ];

    /**
     * @param \DateTimeImmutable $now в часовом поясе бизнес-периодов (МСК)
     */
    public function build(ServiceCoverageMonth $month, \DateTimeImmutable $now): CoverageHeatmap
    {
        $first = \DateTimeImmutable::createFromFormat('!Y-m', $month->month, $now->getTimezone());
        if (false === $first) {
            throw new \InvalidArgumentException(sprintf('Invalid month "%s".', $month->month));
        }
        $daysInMonth = (int) $first->format('t');
        $today = $now->format('Y-m-d');
        $currentMonth = $now->format('Y-m') === $month->month;

        $days = [];
        for ($d = 1; $d <= $daysInMonth; ++$d) {
            $date = $first->setDate((int) $first->format('Y'), (int) $first->format('m'), $d);
            $days[] = new CoverageHeatmapDay($d, (int) $date->format('N') >= 6, $date->format('Y-m-d') === $today);
        }

        /** @var array<string, array<string, ServiceCoverageMonthCell>> $byResource */
        $byResource = [];
        foreach ($month->cells as $cell) {
            $byResource[$cell->resourceType][$cell->periodKey] = $cell;
        }

        $order = array_keys(self::RESOURCES);
        foreach (array_keys($byResource) as $resourceType) {
            if (!\in_array($resourceType, $order, true)) {
                $order[] = $resourceType;
            }
        }

        $rows = [];
        $done = 0;
        $expected = 0;
        foreach ($order as $resourceType) {
            $cells = $byResource[$resourceType] ?? [];
            [$title, $step] = self::RESOURCES[$resourceType] ?? self::OPTIONAL_RESOURCES[$resourceType] ?? [$resourceType, $this->guessStep($cells)];
            $firstLoaded = isset(self::OPTIONAL_RESOURCES[$resourceType]) && [] !== $cells ? min(array_map('strval', array_keys($cells))) : null;
            $perDay = self::STEP_DAY === $step;
            $built = [];
            /** @var list<ServiceCoverageMonthCell|null> $periods наступившие периоды строки */
            $periods = [];
            if ($perDay) {
                foreach ($days as $day) {
                    $key = sprintf('%s-%02d', $month->month, $day->day);
                    $isFuture = $key >= $today;
                    $built[] = $this->cell($title, $this->dayLabel($key), $cells[$key] ?? null, $isFuture);
                    if (!$isFuture && (null === $firstLoaded || $key >= $firstLoaded)) {
                        $periods[] = $cells[$key] ?? null;
                    }
                }
            } else {
                $isRef = self::STEP_REF === $step;
                $key = $isRef ? 'ref' : $month->month;
                $period = $isRef ? 'справочник' : 'месяц '.$month->month;
                // Месячный отчёт за текущий месяц источник ещё не формирует; справочник от месяца не зависит.
                $isFuture = $currentMonth && !$isRef;
                $built[] = $this->cell($title, $period, $cells[$key] ?? null, $isFuture);
                if (!$isFuture) {
                    $periods[] = $cells[$key] ?? null;
                }
            }

            // Процент — по всем наступившим периодам строки: период без задачи — пропуск
            // (0 из ожидаемых), иначе одна загруженная ячейка дала бы 100 % при дырах в сетке.
            // Ожидаемое для пропуска — как у соседних ячеек отчёта, иначе все кабинеты снимка.
            $fallback = [] === $cells ? ($month->snapshot->cabinetsTotal ?? 0) : max(array_map(static fn (ServiceCoverageMonthCell $c): int => $c->cabinetsExpected, $cells));
            foreach ($periods as $cell) {
                $cellExpected = $cell->cabinetsExpected ?? $fallback;
                $expected += $cellExpected;
                $done += null === $cell ? 0 : min($cell->byStatus->done, $cellExpected);
            }

            $rows[] = new CoverageHeatmapRow(
                title: $title,
                resourceType: $resourceType,
                perDay: $perDay,
                cells: $built,
                errCount: \count(array_filter($built, static fn (CoverageHeatmapCell $c): bool => CoverageHeatmapCell::ERR === $c->state)),
                partialCount: \count(array_filter($built, static fn (CoverageHeatmapCell $c): bool => CoverageHeatmapCell::PARTIAL === $c->state)),
            );
        }

        $snapshot = $month->snapshot;

        return new CoverageHeatmap(
            month: $month->month,
            days: $days,
            rows: $rows,
            refreshedAt: $snapshot?->refreshedAt,
            stale: null !== $snapshot && $now->getTimestamp() - $snapshot->refreshedAt->getTimestamp() > self::STALE_AFTER_SECONDS,
            cabinetsTotal: $snapshot?->cabinetsTotal,
            coveragePercent: $expected > 0 ? (int) floor($done * 100 / $expected) : null,
        );
    }

    private function cell(string $title, string $period, ?ServiceCoverageMonthCell $cell, bool $isFuture): CoverageHeatmapCell
    {
        if (null === $cell) {
            return $isFuture
                ? new CoverageHeatmapCell(CoverageHeatmapCell::FUTURE, '', sprintf("%s · %s\nПериод ещё не наступил", $title, $period))
                : new CoverageHeatmapCell(CoverageHeatmapCell::EMPTY, '—', sprintf("%s · %s\nЗадач нет", $title, $period));
        }

        $counts = $cell->byStatus;
        if (0 === $cell->cabinetsExpected && 0 === $counts->total()) {
            return new CoverageHeatmapCell(CoverageHeatmapCell::EMPTY, '—', sprintf("%s · %s\nЗадач нет", $title, $period));
        }

        $state = match (true) {
            $counts->failedPermanent > 0 => CoverageHeatmapCell::ERR,
            $counts->done >= $cell->cabinetsExpected => CoverageHeatmapCell::OK,
            default => CoverageHeatmapCell::PARTIAL,
        };

        $hint = [sprintf('%s · %s', $title, $period), sprintf('Загружено: %d из %d кабинетов', $counts->done, $cell->cabinetsExpected)];
        $other = [];
        foreach (self::STATUS_LABELS as $field => $label) {
            if ($counts->{$field} > 0) {
                $other[] = sprintf('%s: %d', $label, $counts->{$field});
            }
        }
        $missing = $cell->cabinetsExpected - $counts->total();
        if ($missing > 0) {
            $other[] = sprintf('без задачи: %d', $missing);
        }
        if ([] !== $other) {
            $hint[] = implode(' · ', $other);
        }
        $hint[] = null === $cell->lastSuccessAt
            ? 'Успешной загрузки ещё не было'
            : 'Последняя успешная: '.$cell->lastSuccessAt->format('d.m.Y H:i');

        return new CoverageHeatmapCell($state, sprintf('%d/%d', $counts->done, $cell->cabinetsExpected), implode("\n", $hint));
    }

    /**
     * Шаг незнакомого отчёта — по ключам его периодов.
     *
     * @param array<string, ServiceCoverageMonthCell> $cells
     */
    private function guessStep(array $cells): string
    {
        $keys = array_map('strval', array_keys($cells));
        foreach ($keys as $key) {
            if (10 === \strlen($key)) {
                return self::STEP_DAY;
            }
        }

        return \in_array('ref', $keys, true) ? self::STEP_REF : self::STEP_MONTH;
    }

    private function dayLabel(string $date): string
    {
        return implode('.', array_reverse(explode('-', $date)));
    }
}
