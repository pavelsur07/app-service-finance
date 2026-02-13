<?php

namespace App\Analytics\Application\Widget;

final class ParetoTopItemsBuilder
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{items:list<array<string,mixed>>,other:array{label:string,sum:float,share:float}}
     */
    public function split(array $rows, float $total, float $coverageTarget, int $maxItems): array
    {
        $items = [];
        $otherSum = 0.0;
        $cumulativeSum = 0.0;

        foreach ($rows as $row) {
            $sum = (float) ($row['sum'] ?? 0.0);

            if (\count($items) < $maxItems && ($total <= 0.0 || ($cumulativeSum / $total) < $coverageTarget)) {
                $items[] = $row;
                $cumulativeSum += $sum;
                continue;
            }

            $otherSum += $sum;
        }

        return [
            'items' => $items,
            'other' => [
                'label' => 'Прочее',
                'sum' => round($otherSum, 2),
                'share' => $total > 0.0 ? round($otherSum / $total, 4) : 0.0,
            ],
        ];
    }
}
