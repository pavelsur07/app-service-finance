<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\View\BudgetPlanFactRow;
use App\Budget\Application\View\BudgetPlanFactView;
use App\Budget\Domain\BudgetVariance;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Infrastructure\Query\BudgetCellsQuery;
use App\Finance\Enum\PLValueFormat;
use App\Finance\Facade\FinanceFacade;

/**
 * План–факт варианта: план (ячейки бюджета) и факт (регистр ОПиУ через Finance) считаются одним
 * калькулятором дерева, отклонение — BudgetVariance. Пара «проект × ЦФО» фильтрует оба одинаково
 * (точная пара); без пары — вся компания. Только чтение.
 */
final readonly class BudgetPlanFactBuilder
{
    public function __construct(
        private BudgetCellsQuery $cells,
        private BudgetTreeCalculator $tree,
        private FinanceFacade $finance,
    ) {
    }

    public function build(BudgetVersion $version, ?string $projectDirectionId, ?string $responsibilityCenterId): BudgetPlanFactView
    {
        $companyId = $version->getCompanyId();
        $year = $version->getYear();

        /** @var array<string, array<string, string>> $planAmounts */
        $planAmounts = [];
        foreach ($this->cells->fetch($companyId, $version->getId(), $projectDirectionId, $responsibilityCenterId) as $cell) {
            $planAmounts[$cell->month][$cell->plCategoryId] = $cell->amount;
        }
        $factAmounts = $this->finance->getPlFactAmountsByMonth($companyId, $year, $projectDirectionId, $responsibilityCenterId);

        $plan = $this->tree->calculate($companyId, $year, $planAmounts);
        $fact = $this->tree->calculate($companyId, $year, $factAmounts)->byId();

        $rows = [];
        foreach ($plan->rows as $planRow) {
            $row = $planRow->row;
            $money = PLValueFormat::MONEY === $row->format;
            $cells = [];
            foreach ($planRow->values as $key => $planValue) {
                $factValue = $fact[$row->id]->values[$key] ?? ($money ? '0.00' : '0.0000');
                $cells[$key] = BudgetVariance::of($planValue, $factValue, $money);
            }
            $rows[] = new BudgetPlanFactRow($row->id, $row->code, $row->name, $row->level, $row->type->value, $row->format->value, $cells);
        }

        return new BudgetPlanFactView($version->getId(), $year, BudgetVariance::THRESHOLD_PERCENT, $plan->months, $rows, $plan->warnings);
    }
}
