<?php

declare(strict_types=1);

namespace App\Finance\Report;

use App\Entity\Company;
use App\Entity\ProjectDirection;

final class PlReportGridBuilder
{
    public function __construct(
        private readonly PlReportCalculator $calc,
    ) {
    }

    /**
     * @return array{
     *   periods: PlReportPeriod[],
     *   rows: array<int,array{id:string,code:?string,name:string,level:int,type:string,values: array<string,string>}>,
     *   rawValues: array<string, array<string,float>>,
     *   warnings: string[],
     * }
     */
    public function build(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $grouping,
        ?ProjectDirection $projectDirection = null
    ): array {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $periods = $this->buildPeriods($from, $to, $grouping);

        $results = [];
        $warnings = [];
        foreach ($periods as $period) {
            $result = $this->calc->calculate($company, $period, $projectDirection);
            $results[] = $result;
            $warnings = array_merge($warnings, $result->warnings);
        }
        $warnings = array_values(array_unique($warnings));

        $rows = [];
        $rawValues = [];
        if ([] !== $results) {
            foreach ($results[0]->rows as $row) {
                $rows[$row->id] = [
                    'id' => $row->id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'level' => $row->level,
                    'type' => $row->type,
                    'values' => [],
                ];
                $rawValues[$row->id] = [];
            }

            foreach ($results as $result) {
                foreach ($result->rows as $row) {
                    $rows[$row->id]['values'][$result->period->id] = $row->formatted;
                    $rawValues[$row->id][$result->period->id] = $row->rawValue;
                }
            }
        }

        return [
            'periods' => $periods,
            'rows' => array_values($rows),
            'rawValues' => $rawValues,
            'warnings' => $warnings,
        ];
    }

    /** @return PlReportPeriod[] */
    private function buildPeriods(\DateTimeImmutable $from, \DateTimeImmutable $to, string $grouping): array
    {
        $periods = [];
        $start = $from->setTime(0, 0, 0);
        $endBound = $to->setTime(23, 59, 59);

        while ($start <= $endBound) {
            $periodStart = $start;
            switch ($grouping) {
                case 'day':
                    $candidateEnd = $periodStart;
                    $label = $periodStart->format('d.m.Y');
                    break;
                case 'week':
                    $candidateEnd = $periodStart->modify('sunday this week');
                    $label = '';
                    break;
                case 'month':
                default:
                    $candidateEnd = $periodStart->modify('last day of this month');
                    $label = $periodStart->format('Y-m');
                    break;
            }

            $candidateEnd = $candidateEnd->setTime(23, 59, 59);
            if ($candidateEnd > $endBound) {
                $periodEnd = $endBound;
            } else {
                $periodEnd = $candidateEnd;
            }

            if ('week' === $grouping) {
                $label = sprintf(
                    'Неделя %s (%s — %s)',
                    $periodStart->format('W'),
                    $periodStart->format('d.m.Y'),
                    $periodEnd->format('d.m.Y')
                );
            }

            $periods[] = new PlReportPeriod($periodStart, $periodEnd, $label);
            $start = $periodEnd->modify('+1 day')->setTime(0, 0, 0);
        }

        return $periods;
    }
}
