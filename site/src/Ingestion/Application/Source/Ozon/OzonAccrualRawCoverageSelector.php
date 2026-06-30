<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

/**
 * Selects the newest non-overlapping raw coverage for Ozon accrual by-day rows.
 *
 * @phpstan-type RawRow array<string, mixed>
 */
final readonly class OzonAccrualRawCoverageSelector
{
    /**
     * @param list<RawRow> $candidates
     *
     * @return list<RawRow>
     */
    public function selectLatest(array $candidates, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $fromDay = $from->setTime(0, 0);
        $toDay = $to->setTime(0, 0);
        $winnersByDay = [];

        foreach ($candidates as $candidate) {
            $windowFrom = $this->dateValue($candidate['window_from'] ?? null);
            $windowTo = $this->dateValue($candidate['window_to'] ?? null);
            if (null === $windowFrom || null === $windowTo || $windowFrom > $toDay || $windowTo < $fromDay) {
                continue;
            }

            $cursor = $windowFrom > $fromDay ? $windowFrom : $fromDay;
            $lastDay = $windowTo < $toDay ? $windowTo : $toDay;
            for (; $cursor <= $lastDay; $cursor = $cursor->modify('+1 day')) {
                $day = $cursor->format('Y-m-d');
                $key = $this->coverageKey($candidate, $day);
                $current = $winnersByDay[$key] ?? null;
                if (null === $current || $this->isNewer($candidate, $current)) {
                    $winnersByDay[$key] = $candidate;
                }
            }
        }

        $selected = [];
        foreach ($winnersByDay as $key => $candidate) {
            $day = substr((string) $key, -10);
            $id = (string) $candidate['id'];
            $selected[$id] ??= $candidate + ['selected_dates' => []];
            $selected[$id]['selected_dates'][$day] = $day;
        }

        $rows = array_values(array_map(static function (array $row): array {
            $selectedDates = array_values($row['selected_dates']);
            sort($selectedDates);
            $row['selected_dates'] = $selectedDates;

            return $row;
        }, $selected));

        usort($rows, fn (array $left, array $right): int => $this->compareForDisplay($left, $right));

        return $rows;
    }

    /**
     * @param RawRow $row
     */
    private function coverageKey(array $row, string $day): string
    {
        return implode('|', [
            (string) ($row['company_id'] ?? ''),
            (string) ($row['shop_ref'] ?? ''),
            $day,
        ]);
    }

    /**
     * @param RawRow $candidate
     * @param RawRow $current
     */
    private function isNewer(array $candidate, array $current): bool
    {
        foreach (['fetched_at', 'created_at', 'id'] as $field) {
            $left = $this->comparableValue($candidate[$field] ?? null);
            $right = $this->comparableValue($current[$field] ?? null);
            if ($left === $right) {
                continue;
            }

            return $left > $right;
        }

        return false;
    }

    /**
     * @param RawRow $left
     * @param RawRow $right
     */
    private function compareForDisplay(array $left, array $right): int
    {
        foreach (['company_id', 'shop_ref', 'window_from', 'window_to', 'fetched_at', 'created_at', 'id'] as $field) {
            $leftValue = $this->comparableValue($left[$field] ?? null);
            $rightValue = $this->comparableValue($right[$field] ?? null);
            if ($leftValue === $rightValue) {
                continue;
            }

            return $leftValue <=> $rightValue;
        }

        return 0;
    }

    private function dateValue(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        if (!is_scalar($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim((string) $value), 0, 10));

        return false === $date ? null : $date;
    }

    private function comparableValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        return trim((string) $value);
    }
}
