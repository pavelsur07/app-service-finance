<?php

declare(strict_types=1);

namespace App\Finance\Report;

use App\Company\Entity\ProjectDirection;
use App\Entity\Company;
use App\Finance\Engine\ValueFormatter;
use App\Repository\PLCategoryRepository;

final class PlReportProjectsCompareBuilder
{
    public function __construct(
        private readonly PlReportCalculator $calc,
        private readonly PLCategoryRepository $plCategories,
        private readonly ValueFormatter $fmt,
    ) {
    }

    /**
     * @param ProjectDirection[] $projects
     *
     * @return array{
     *   period: array{id:string,label:string,from:string,to:string},
     *   projects: array<int,array{id:string,name:string,isOverhead:bool}>,
     *   rows: array<int,array{id:string,code:?string,name:string,level:int,type:string,values: array<string,string>}>,
     *   rawValues: array<string, array<string,float>>,
     *   warnings: string[],
     * }
     */
    public function build(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $projects,
        ?ProjectDirection $overheadProject = null,
    ): array {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // период “итого за диапазон”
        $period = PlReportPeriod::forRange($from, $to, 'Итого');

        // карта форматов по id категории (для форматирования total)
        $formatById = [];
        foreach ($this->plCategories->findBy(['company' => $company]) as $cat) {
            if ($cat->getId()) {
                $formatById[(string) $cat->getId()] = $cat->getFormat();
            }
        }

        $warnings = [];
        $rowsById = [];
        $rawValues = [];

        if (!$this->calc->supportsProjectDimension()) {
            throw new \LogicException('P&L projects view requires project dimension support in facts');
        }

        // считаем по каждому проекту
        foreach ($projects as $p) {
            if (!$p->getId()) {
                continue;
            }
            $projectId = (string) $p->getId();

            $result = $this->calc->calculate($company, $period, $p);
            $warnings = array_merge($warnings, $result->warnings);

            foreach ($result->rows as $r) {
                $rowId = (string) $r->id;

                if (!isset($rowsById[$rowId])) {
                    $rowsById[$rowId] = [
                        'id' => $r->id,
                        'code' => $r->code,
                        'name' => $r->name,
                        'level' => $r->level,
                        'type' => $r->type,
                        'values' => [],
                    ];
                    $rawValues[$rowId] = [];
                }

                $rowsById[$rowId]['values'][$projectId] = $r->formatted;
                $rawValues[$rowId][$projectId] = (float) $r->rawValue;
            }
        }

        // добавляем колонку "_total"
        foreach ($rowsById as $rowId => &$row) {
            $sum = 0.0;
            foreach (($rawValues[$rowId] ?? []) as $v) {
                $sum += (float) $v;
            }
            $rawValues[$rowId]['_total'] = $sum;

            $format = $formatById[$rowId] ?? null;
            if ($format) {
                $row['values']['_total'] = $this->fmt->format($sum, $format);
            } else {
                // fallback безопасно: деньги
                $row['values']['_total'] = $this->fmt->format($sum, \App\Enum\PLValueFormat::MONEY);
            }
        }
        unset($row);

        $projectsPayload = [];
        foreach ($projects as $p) {
            if (!$p->getId()) {
                continue;
            }
            $projectsPayload[] = [
                'id' => (string) $p->getId(),
                'name' => (string) $p->getName(),
                'isOverhead' => $overheadProject && (string) $overheadProject->getId() === (string) $p->getId(),
            ];
        }

        return [
            'period' => [
                'id' => 'range',
                'label' => 'Итого',
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'projects' => $projectsPayload,
            'rows' => array_values($rowsById),
            'rawValues' => $rawValues,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
