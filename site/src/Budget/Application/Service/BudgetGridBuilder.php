<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\View\BudgetGridRow;
use App\Budget\Application\View\BudgetGridRule;
use App\Budget\Application\View\BudgetGridView;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Infrastructure\Query\BudgetCellsQuery;
use App\Budget\Infrastructure\Query\BudgetRulesQuery;
use App\Finance\Enum\PLCategoryType;

/**
 * Сетка варианта: дерево ОПиУ × 12 месяцев + год. Листья — суммы плана как есть; итоги, формулы
 * и KPI — калькулятором отчёта ОПиУ (`FinanceFacade::calculatePlTree`), год — отдельной колонкой
 * (KPI года не равен сумме месяцев). Лист без кода отчёт не считает — в итоги он не входит, об
 * этом предупреждение. Только чтение.
 */
final readonly class BudgetGridBuilder
{
    public function __construct(
        private BudgetCellsQuery $cells,
        private BudgetTreeCalculator $tree,
        private BudgetRulesQuery $rules,
    ) {
    }

    public function build(BudgetVersion $version, ?string $projectDirectionId, ?string $responsibilityCenterId): BudgetGridView
    {
        /** @var array<string, array<string, string>> $amounts `Y-m` → categoryId → amount */
        $amounts = [];
        /** @var array<string, array<string, string>> $comments categoryId → `Y-m` → comment */
        $comments = [];
        foreach ($this->cells->fetch($version->getCompanyId(), $version->getId(), $projectDirectionId, $responsibilityCenterId) as $cell) {
            $amounts[$cell->month][$cell->plCategoryId] = $cell->amount;
            if (null !== $cell->comment) {
                $comments[$cell->plCategoryId][$cell->month] = $cell->comment;
            }
        }

        $tree = $this->tree->calculate($version->getCompanyId(), $version->getYear(), $amounts);
        $rules = null !== $projectDirectionId && null !== $responsibilityCenterId
            ? $this->rules->targetsOfPair($version->getCompanyId(), $version->getId(), $projectDirectionId, $responsibilityCenterId)
            : [];
        $names = [];
        foreach ($tree->rows as $treeRow) {
            $names[$treeRow->row->id] = $treeRow->row->name;
        }

        $rows = [];
        foreach ($tree->rows as $treeRow) {
            $row = $treeRow->row;
            $values = $treeRow->values;
            $total = $values[BudgetTreeCalculator::TOTAL];
            unset($values[BudgetTreeCalculator::TOTAL]);
            $rows[] = new BudgetGridRow(
                id: $row->id,
                code: $row->code,
                name: $row->name,
                level: $row->level,
                type: $row->type->value,
                flow: strtolower($treeRow->category->flow->value),
                format: $row->format->value,
                editable: PLCategoryType::LEAF_INPUT === $row->type && !isset($rules[$row->id]),
                values: $values,
                total: $total,
                comments: $comments[$row->id] ?? [],
                rule: isset($rules[$row->id])
                    ? new BudgetGridRule($rules[$row->id]->percent, $rules[$row->id]->baseCategoryId, $names[$rules[$row->id]->baseCategoryId] ?? 'строка удалена')
                    : null,
            );
        }

        return new BudgetGridView($version->getId(), $version->getYear(), $tree->months, $rows, $tree->warnings);
    }
}
