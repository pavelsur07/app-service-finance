<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\View\BudgetPlanFactColumn;
use App\Budget\Application\View\BudgetPlanFactRow;
use App\Budget\Application\View\BudgetPlanFactView;
use App\Budget\Domain\BudgetVariance;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Infrastructure\Query\BudgetCellsQuery;
use App\Finance\Enum\PLValueFormat;
use App\Finance\Facade\FinanceFacade;
use Psr\Clock\ClockInterface;

/**
 * План–факт варианта: план (ячейки бюджета) и факт (регистр ОПиУ через Finance) считаются одним
 * калькулятором дерева, отклонение — BudgetVariance. Пара «проект × ЦФО» фильтрует оба одинаково
 * (точная пара); без пары — вся компания. Только чтение.
 *
 * Колонки — месяцы, кварталы, «с начала года» и год (BudgetPlanFactColumn). Закрыт месяц раньше
 * текущего по Москве; итоговые колонки сравнивают только закрытые месяцы, год — только план.
 */
final readonly class BudgetPlanFactBuilder
{
    public function __construct(
        private BudgetCellsQuery $cells,
        private BudgetTreeCalculator $tree,
        private FinanceFacade $finance,
        private ClockInterface $clock,
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

        $currentMonth = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m');
        $columns = $this->columns($year, $currentMonth);
        $columnMonths = [];
        foreach ($columns as $column) {
            $columnMonths[$column->key] = $column->months;
        }
        $plan = $this->tree->calculateColumns($companyId, $year, $planAmounts, $columnMonths);
        $fact = $this->tree->calculateColumns($companyId, $year, $factAmounts, $columnMonths)->byId();

        $rows = [];
        foreach ($plan->rows as $planRow) {
            $row = $planRow->row;
            $money = PLValueFormat::MONEY === $row->format;
            $cells = [];
            foreach ($columns as $column) {
                $planValue = $planRow->values[$column->key];
                $factValue = $fact[$row->id]->values[$column->key] ?? ($money ? '0.00' : '0.0000');
                $cells[$column->key] = match (true) {
                    $column->compared() => BudgetVariance::of($planValue, $factValue, $money),
                    BudgetPlanFactColumn::CURRENT === $column->state => BudgetVariance::notCompared($planValue, $factValue),
                    default => BudgetVariance::notCompared($planValue, null),
                };
            }
            $rows[] = new BudgetPlanFactRow($row->id, $row->code, $row->name, $row->level, $row->type->value, $row->format->value, $cells);
        }

        return new BudgetPlanFactView($version->getId(), $year, BudgetVariance::THRESHOLD_PERCENT, $plan->months, $columns, str_starts_with($currentMonth, $year.'-') ? $currentMonth : null, $rows, $plan->warnings);
    }

    /**
     * @return list<BudgetPlanFactColumn>
     */
    private function columns(int $year, string $currentMonth): array
    {
        $months = BudgetTreeCalculator::months($year);
        $closed = array_values(array_filter($months, static fn (string $month): bool => $month < $currentMonth));
        $columns = [];
        foreach ($months as $month) {
            $state = $month < $currentMonth ? BudgetPlanFactColumn::CLOSED : ($month === $currentMonth ? BudgetPlanFactColumn::CURRENT : BudgetPlanFactColumn::FUTURE);
            $columns[] = new BudgetPlanFactColumn($month, BudgetPlanFactColumn::KIND_MONTH, $state, [$month]);
        }
        foreach (array_chunk($months, 3) as $index => $quarter) {
            $quarterClosed = array_values(array_intersect($quarter, $closed));
            [$state, $quarterMonths] = match (true) {
                $quarterClosed === $quarter => [BudgetPlanFactColumn::CLOSED, $quarter],
                [] !== $quarterClosed => [BudgetPlanFactColumn::PARTIAL, $quarterClosed],
                in_array($currentMonth, $quarter, true) => [BudgetPlanFactColumn::CURRENT, $quarter],
                default => [BudgetPlanFactColumn::FUTURE, $quarter],
            };
            $columns[] = new BudgetPlanFactColumn(sprintf('%04d-Q%d', $year, $index + 1), BudgetPlanFactColumn::KIND_QUARTER, $state, $quarterMonths);
        }
        $columns[] = new BudgetPlanFactColumn(BudgetPlanFactColumn::YTD, BudgetPlanFactColumn::KIND_YTD, [] === $closed ? BudgetPlanFactColumn::FUTURE : BudgetPlanFactColumn::CLOSED, $closed);
        $columns[] = new BudgetPlanFactColumn(BudgetPlanFactColumn::YEAR, BudgetPlanFactColumn::KIND_YEAR, BudgetPlanFactColumn::FUTURE, $months);

        return $columns;
    }
}
