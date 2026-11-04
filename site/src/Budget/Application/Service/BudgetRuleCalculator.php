<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\DTO\BudgetCellRow;
use App\Budget\Domain\BudgetAmount;
use App\Budget\Domain\BudgetFillMath;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Infrastructure\Query\BudgetCellsQuery;
use App\Budget\Infrastructure\Query\BudgetRulesQuery;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Facade\PLCategoryFacade;

/**
 * Пересчёт ячеек по правилам «X % от строки-базы» одной пары (задача pl-budget, этап 8).
 *
 * База — строка дерева ОПиУ по плану пары **без статей с правилом** (как у помощника percent_of):
 * правило не зависит от других правил, циклов и цепочек нет. Сумма месяца — X % от базы, знак — по
 * направлению статьи (BudgetFillMath), округление до копейки. Пишутся только изменившиеся ячейки,
 * источник `rule`, комментарий сохраняется; ноль без комментария удаляет строку.
 *
 * Без своей транзакции: вызывает точка входа записи плана под блокировкой варианта
 * ({@see \App\Budget\Application\BudgetVersionLock}) в той же транзакции, что и запись базы.
 */
final readonly class BudgetRuleCalculator
{
    public function __construct(
        private BudgetCellsQuery $cells,
        private BudgetRulesQuery $rules,
        private BudgetTreeCalculator $tree,
        private PLCategoryFacade $categories,
        private BudgetLinesWriter $writer,
    ) {
    }

    /**
     * @return int число записанных ячеек
     */
    public function recalculate(string $companyId, string $versionId, int $year, string $projectDirectionId, string $responsibilityCenterId): int
    {
        $rules = $this->rules->targetsOfPair($companyId, $versionId, $projectDirectionId, $responsibilityCenterId);
        if ([] === $rules) {
            return 0;
        }

        /** @var array<string, array<string, BudgetCellRow>> $existing */
        $existing = [];
        $amounts = [];
        foreach ($this->cells->fetch($companyId, $versionId, $projectDirectionId, $responsibilityCenterId) as $cell) {
            $existing[$cell->plCategoryId][$cell->month] = $cell;
            if (!isset($rules[$cell->plCategoryId])) {
                $amounts[$cell->month][$cell->plCategoryId] = $cell->amount;
            }
        }
        $tree = $this->tree->calculate($companyId, $year, $amounts)->byId();
        $flows = [];
        foreach ($this->categories->getTreeByCompanyId($companyId) as $category) {
            if (PLCategoryType::LEAF_INPUT === $category->type) {
                $flows[$category->id] = $category->flow;
            }
        }

        $cells = [];
        foreach ($rules as $categoryId => $rule) {
            $flow = $flows[$categoryId] ?? null;
            if (null === $flow) {
                continue; // статья удалена или перестала быть листом — правило ждёт снятия
            }
            $base = $tree[$rule->baseCategoryId] ?? null;
            foreach (BudgetTreeCalculator::months($year) as $month) {
                $amount = BudgetFillMath::signByFlow(BudgetFillMath::percentOf($base?->values[$month] ?? '0', $rule->percent), $flow);
                $current = $existing[$categoryId][$month] ?? null;
                if (null === $current ? BudgetAmount::isZero($amount) : (BudgetLineSource::RULE->value === $current->source && 0 === bccomp($amount, $current->amount, 2))) {
                    continue;
                }
                $cells[$categoryId.'|'.$month] = [
                    'category' => $categoryId,
                    'month' => $month.'-01',
                    'project' => $projectDirectionId,
                    'center' => $responsibilityCenterId,
                    'amount' => $amount,
                    'comment' => $current?->comment,
                ];
            }
        }
        if ([] !== $cells) {
            $this->writer->write($companyId, $versionId, $cells, BudgetLineSource::RULE);
        }

        return \count($cells);
    }
}
