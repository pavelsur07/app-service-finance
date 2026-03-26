<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Reconciliation;

/**
 * Шаги 4-5: агрегирует классифицированные строки в ReportResult.
 */
final class ReportAggregatorService
{
    /**
     * @param array<int, array<string, mixed>> $classifiedRows
     * @return array<string, mixed> ReportResult
     */
    public function aggregate(string $period, array $classifiedRows, array $baseSignMap): array
    {
        $lines = [];

        foreach ($classifiedRows as $row) {
            $typeName = $row['typeName'];
            $amount   = (float) $row['amount'];
            $class    = $row['class'];

            if (!isset($lines[$typeName])) {
                $lines[$typeName] = [
                    'typeName'     => $typeName,
                    'serviceGroup' => $row['serviceGroup'],
                    'baseSign'     => $baseSignMap[$typeName] ?? '-',
                    'accruals'     => ['count' => 0, 'total' => 0.0],
                    'expenses'     => ['count' => 0, 'total' => 0.0],
                    'storno'       => ['count' => 0, 'total' => 0.0],
                    'zero'         => ['count' => 0, 'total' => 0.0],
                ];
            }

            if ($class === RowClassifierService::ACCRUAL) {
                $lines[$typeName]['accruals']['count']++;
                $lines[$typeName]['accruals']['total'] += $amount;
            } elseif ($class === RowClassifierService::EXPENSE) {
                $lines[$typeName]['expenses']['count']++;
                $lines[$typeName]['expenses']['total'] += $amount;
            } elseif ($class === RowClassifierService::STORNO) {
                $lines[$typeName]['storno']['count']++;
                $lines[$typeName]['storno']['total'] += $amount;
            } else {
                $lines[$typeName]['zero']['count']++;
            }
        }

        ksort($lines);
        $lines = array_values($lines);

        $totalAccruals = 0.0;
        $totalExpenses = 0.0;
        $totalStorno   = 0.0;

        foreach ($lines as &$line) {
            $line['accruals']['total'] = round($line['accruals']['total'], 2);
            $line['expenses']['total'] = round($line['expenses']['total'], 2);
            $line['storno']['total']   = round($line['storno']['total'], 2);
            $totalAccruals += $line['accruals']['total'];
            $totalExpenses += $line['expenses']['total'];
            $totalStorno   += $line['storno']['total'];
        }
        unset($line);

        return [
            'period'        => $period,
            'lines'         => $lines,
            'totalAccruals' => round($totalAccruals, 2),
            'totalExpenses' => round($totalExpenses, 2),
            'totalStorno'   => round($totalStorno, 2),
            'totalNet'      => round($totalAccruals + $totalExpenses + $totalStorno, 2),
        ];
    }
}
