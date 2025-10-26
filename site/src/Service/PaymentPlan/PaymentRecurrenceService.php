<?php

namespace App\Service\PaymentPlan;

use App\Entity\PaymentPlan;
use App\Entity\PaymentRecurrenceRule;

final class PaymentRecurrenceService
{
    /**
     * @return PaymentPlan[]
     */
    public function expandOccurrences(PaymentPlan $seed, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rule = $seed->getRecurrenceRule();
        if (null === $rule || !$rule->isActive()) {
            return [];
        }

        $rangeStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        $rangeEnd = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);

        if ($rangeEnd < $rangeStart) {
            return [];
        }

        if (null !== $rule->getUntil()) {
            $until = $rule->getUntil()->setTime(0, 0);
            if ($until < $rangeEnd) {
                $rangeEnd = $until;
            }
        }

        $start = $seed->getPlannedAt()->setTime(0, 0);

        $dates = match ($rule->getFrequency()) {
            PaymentRecurrenceRule::FREQUENCY_WEEKLY => $this->generateWeeklyOccurrences($start, $rule, $rangeStart, $rangeEnd),
            PaymentRecurrenceRule::FREQUENCY_MONTHLY => $this->generateMonthlyOccurrences($start, $rule, $rangeStart, $rangeEnd, 1),
            PaymentRecurrenceRule::FREQUENCY_QUARTERLY => $this->generateMonthlyOccurrences($start, $rule, $rangeStart, $rangeEnd, 3),
            default => [],
        };

        $result = [];
        foreach ($dates as $date) {
            $clone = clone $seed;
            $clone->setPlannedAt(\DateTimeImmutable::createFromInterface($date));
            $result[] = $clone;
        }

        return $result;
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function generateWeeklyOccurrences(\DateTimeImmutable $start, PaymentRecurrenceRule $rule, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        $interval = max(1, $rule->getInterval());
        $days = $this->parseByDay($rule->getByDay(), $start);
        sort($days);

        $weekStart = $start->modify('monday this week');
        $occurrences = [];
        $weekIndex = 0;

        while (true) {
            $currentWeekStart = $weekStart->modify(sprintf('+%d weeks', $weekIndex * $interval));
            $earliestThisWeek = $currentWeekStart->modify(sprintf('+%d days', $days[0] - 1));

            if ($earliestThisWeek > $rangeEnd) {
                break;
            }

            foreach ($days as $day) {
                $candidate = $currentWeekStart->modify(sprintf('+%d days', $day - 1));
                if ($candidate <= $start) {
                    continue;
                }
                if ($candidate < $rangeStart) {
                    continue;
                }
                if ($candidate > $rangeEnd) {
                    continue;
                }

                $occurrences[] = $candidate;
            }

            ++$weekIndex;
        }

        return $occurrences;
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function generateMonthlyOccurrences(\DateTimeImmutable $start, PaymentRecurrenceRule $rule, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, int $intervalMultiplier): array
    {
        $interval = max(1, $rule->getInterval()) * max(1, $intervalMultiplier);
        $dayOfMonth = $rule->getDayOfMonth();
        if (null === $dayOfMonth || $dayOfMonth < 1) {
            $dayOfMonth = (int) $start->format('j');
        }

        $baseYear = (int) $start->format('Y');
        $baseMonth = (int) $start->format('n');
        $occurrences = [];

        for ($offset = $interval;; $offset += $interval) {
            $totalMonths = ($baseMonth - 1) + $offset;
            $targetYear = $baseYear + intdiv($totalMonths, 12);
            $targetMonth = ($totalMonths % 12) + 1;
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $targetMonth, $targetYear);
            $day = min($dayOfMonth, $daysInMonth);

            $candidate = $start->setDate($targetYear, $targetMonth, $day);

            if ($candidate <= $start) {
                continue;
            }

            if ($candidate > $rangeEnd) {
                break;
            }

            if ($candidate < $rangeStart) {
                continue;
            }

            $occurrences[] = $candidate;
        }

        return $occurrences;
    }

    /**
     * @return list<int>
     */
    private function parseByDay(?string $value, \DateTimeImmutable $start): array
    {
        $map = [
            'MO' => 1,
            'TU' => 2,
            'WE' => 3,
            'TH' => 4,
            'FR' => 5,
            'SA' => 6,
            'SU' => 7,
        ];

        $result = [];
        if (null !== $value && '' !== trim($value)) {
            $parts = preg_split('/[\s,]+/', trim($value));
            foreach ($parts as $part) {
                $token = strtoupper($part);
                if (isset($map[$token])) {
                    $result[$map[$token]] = $map[$token];
                }
            }
        }

        if (empty($result)) {
            $day = (int) $start->format('N');
            $result[$day] = $day;
        }

        ksort($result);

        return array_values($result);
    }
}
