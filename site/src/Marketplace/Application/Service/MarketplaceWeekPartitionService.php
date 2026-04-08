<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

final readonly class MarketplaceWeekPartitionService
{
    /**
     * Нарезает период [$from, $to] на партии с учётом недельных границ и границ месяца.
     *
     * @return list<array{from: string, to: string}>  формат 'Y-m-d H:i:s', from=00:00:00, to=23:59:59
     */
    public function buildPartitions(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0, 0);
        $to   = $to->setTime(0, 0, 0);

        if ($from > $to) {
            return [];
        }

        $partitions = [];
        $cursor = $from;

        while ($cursor <= $to) {
            // Конец недельной партии — ближайшее воскресенье >= $cursor
            $dayOfWeek = (int) $cursor->format('N'); // 1=Пн … 7=Вс
            $daysToSunday = 7 - $dayOfWeek;
            $weekEnd = $cursor->modify("+{$daysToSunday} days");

            // Не выходим за $to
            if ($weekEnd > $to) {
                $weekEnd = $to;
            }

            // Разбить по границам месяца
            $subPartitions = $this->splitByMonthBoundary($cursor, $weekEnd);
            foreach ($subPartitions as $sub) {
                $partitions[] = $sub;
            }

            // Следующая партия — понедельник следующей недели
            $cursor = $weekEnd->modify('+1 day');
        }

        return $partitions;
    }

    /**
     * Если партия пересекает границу месяца — разбивает на две.
     *
     * @return list<array{from: string, to: string}>
     */
    private function splitByMonthBoundary(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ($start->format('Y-m') === $end->format('Y-m')) {
            return [
                [
                    'from' => $start->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
                    'to'   => $end->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
                ],
            ];
        }

        // Последний день месяца начала (modify сохраняет timezone $start)
        $lastDayOfMonth = $start->modify('last day of this month');

        // Первый день следующего месяца
        $firstDayOfNextMonth = $lastDayOfMonth->modify('+1 day');

        return [
            [
                'from' => $start->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
                'to'   => $lastDayOfMonth->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
            ],
            [
                'from' => $firstDayOfNextMonth->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
                'to'   => $end->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
