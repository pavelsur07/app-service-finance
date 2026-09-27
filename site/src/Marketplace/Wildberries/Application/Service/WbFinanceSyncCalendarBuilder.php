<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Application\Service;

use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;

/**
 * Раскладка статусов загрузки отчётов WB за месяц в календарную тепловую карту:
 * строки — дни недели (Пн…Вс), колонки — недели месяца.
 *
 * @phpstan-type CalendarCell array{day: int, state: string, dot: ?string, title: string}
 * @phpstan-type Calendar array{
 *     title: string,
 *     prevMonth: string,
 *     nextMonth: ?string,
 *     weekLabels: list<string>,
 *     weekdays: list<array{label: string, cells: list<?CalendarCell>}>
 * }
 */
final readonly class WbFinanceSyncCalendarBuilder
{
    public const STATE_OK = 'ok';
    public const STATE_PARTIAL = 'partial';
    public const STATE_ERR = 'err';
    public const STATE_EMPTY = 'empty';
    public const STATE_FUTURE = 'future';

    private const MONTH_NAMES = [
        1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
    ];

    private const ERROR_MESSAGE_MAX_WIDTH = 300;

    private const WEEKDAY_LABELS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    /**
     * Месяц из query-параметра `month` (YYYY-MM). Пустое, некорректное или
     * будущее значение — текущий месяц.
     */
    public function resolveMonth(string $value, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $currentMonth = $today->modify('first day of this month')->setTime(0, 0);

        if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return $currentMonth;
        }

        $month = \DateTimeImmutable::createFromFormat('!Y-m', $value, $today->getTimezone());

        if (false === $month || $month > $currentMonth) {
            return $currentMonth;
        }

        return $month;
    }

    /**
     * @param list<array<string, mixed>> $rows строки WbFinanceSyncStatusListQuery за месяц
     *
     * @return Calendar
     */
    public function build(array $rows, \DateTimeImmutable $month, \DateTimeImmutable $today): array
    {
        $monthStart = $month->modify('first day of this month')->setTime(0, 0);
        $todayDate = $today->format('Y-m-d');

        $rowsByDate = [];
        foreach ($rows as $row) {
            $date = substr((string) ($row['business_date'] ?? ''), 0, 10);
            $rowsByDate[$date] ??= $row;
        }

        $offset = (int) $monthStart->format('N') - 1;
        $daysInMonth = (int) $monthStart->format('t');
        $weeksCount = intdiv($offset + $daysInMonth + 6, 7);

        $grid = array_fill(0, 7, array_fill(0, $weeksCount, null));

        /** @var array<int, array{0: int, 1: int}> $weekRanges */
        $weekRanges = [];
        for ($day = 1; $day <= $daysInMonth; ++$day) {
            $index = $offset + $day - 1;
            $week = intdiv($index, 7);
            $date = $monthStart->modify(sprintf('+%d days', $day - 1));
            $dateKey = $date->format('Y-m-d');

            $grid[$index % 7][$week] = $this->cell(
                $day,
                $date,
                $rowsByDate[$dateKey] ?? null,
                $dateKey > $todayDate,
            );
            $weekRanges[$week] = [$weekRanges[$week][0] ?? $day, $day];
        }

        $weekdays = [];
        foreach (self::WEEKDAY_LABELS as $weekday => $label) {
            $weekdays[] = ['label' => $label, 'cells' => array_values($grid[$weekday])];
        }

        $weekLabels = [];
        foreach ($weekRanges as [$first, $last]) {
            $weekLabels[] = $first === $last ? (string) $first : sprintf('%d–%d', $first, $last);
        }

        $nextMonth = $monthStart->modify('first day of next month');

        return [
            'title' => sprintf('%s %s', self::MONTH_NAMES[(int) $monthStart->format('n')], $monthStart->format('Y')),
            'prevMonth' => $monthStart->modify('first day of last month')->format('Y-m'),
            'nextMonth' => $nextMonth->format('Y-m-d') > $todayDate ? null : $nextMonth->format('Y-m'),
            'weekLabels' => $weekLabels,
            'weekdays' => $weekdays,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return CalendarCell
     */
    private function cell(int $day, \DateTimeImmutable $date, ?array $row, bool $isFuture): array
    {
        $dateLabel = $date->format('d.m.Y');

        if ($isFuture) {
            return ['day' => $day, 'state' => self::STATE_FUTURE, 'dot' => null, 'title' => $dateLabel];
        }

        if (null === $row) {
            return ['day' => $day, 'state' => self::STATE_EMPTY, 'dot' => null, 'title' => $dateLabel.' · Нет загрузки'];
        }

        $status = FinancialReportSyncStatus::tryFrom((string) ($row['status'] ?? ''));
        $state = $this->state($status);

        return [
            'day' => $day,
            'state' => $state,
            'dot' => match ($state) {
                self::STATE_PARTIAL => 'warn',
                self::STATE_ERR => 'err',
                default => null,
            },
            'title' => $this->title($dateLabel, $status, $row),
        ];
    }

    private function state(?FinancialReportSyncStatus $status): string
    {
        return match ($status) {
            FinancialReportSyncStatus::SUCCESS,
            FinancialReportSyncStatus::EMPTY => self::STATE_OK,
            FinancialReportSyncStatus::LOADING,
            FinancialReportSyncStatus::RAW_LOADED,
            FinancialReportSyncStatus::PROCESSING,
            FinancialReportSyncStatus::CONFLICT => self::STATE_PARTIAL,
            FinancialReportSyncStatus::FAILED,
            FinancialReportSyncStatus::FAILED_FINAL,
            FinancialReportSyncStatus::AUTH_FAILED => self::STATE_ERR,
            FinancialReportSyncStatus::QUEUED,
            null => self::STATE_EMPTY,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function title(string $dateLabel, ?FinancialReportSyncStatus $status, array $row): string
    {
        $lines = [$dateLabel.' · '.($status?->getLabel() ?? (string) ($row['status'] ?? ''))];

        if (null !== ($row['mode'] ?? null)) {
            $mode = (string) $row['mode'];
            $lines[] = 'Режим: '.(FinancialReportSyncMode::tryFrom($mode)?->getLabel() ?? $mode);
        }

        $lines[] = sprintf('Записей: %d · Попыток: %d', (int) ($row['records_count'] ?? 0), (int) ($row['attempts'] ?? 0));

        if (null !== ($row['updated_at'] ?? null)) {
            $lines[] = 'Обновлено: '.(new \DateTimeImmutable((string) $row['updated_at']))->format('d.m.Y H:i');
        }

        if (null !== ($row['last_error_message'] ?? null)) {
            $parts = array_filter([
                null !== ($row['last_error_status_code'] ?? null) ? 'HTTP '.$row['last_error_status_code'] : '',
                (string) ($row['last_error_class'] ?? ''),
                mb_strimwidth((string) $row['last_error_message'], 0, self::ERROR_MESSAGE_MAX_WIDTH, '…'),
            ], static fn (string $part): bool => '' !== $part);
            $lines[] = 'Ошибка: '.implode(' · ', $parts);
        }

        return implode("\n", $lines);
    }
}
