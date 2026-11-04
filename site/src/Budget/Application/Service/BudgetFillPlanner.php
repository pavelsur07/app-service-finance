<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\DTO\BudgetCellRow;
use App\Budget\Application\DTO\BudgetFillRequest;
use App\Budget\Application\View\BudgetFillCell;
use App\Budget\Application\View\BudgetFillPlan;
use App\Budget\Domain\BudgetAmount;
use App\Budget\Domain\BudgetFillMath;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetFillHelper;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Budget\Infrastructure\Query\BudgetCellsQuery;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Finance\Facade\FinanceFacade;
use App\Finance\Facade\PLCategoryFacade;
use Psr\Clock\ClockInterface;

/**
 * Считает ячейки помощника заполнения для пары «проект × ЦФО» черновика (ui-ux.md, экран 4).
 * Ничего не пишет: запись — FillBudgetCellsAction через SaveBudgetLinesAction.
 *
 * Возвращает только ячейки, где новая сумма отличается от текущей; ячейки с источником
 * «план продаж» пропускаются; комментарий ячейки сохраняется.
 */
final readonly class BudgetFillPlanner
{
    private const array AVERAGE_MONTHS = [3, 6, 12];

    public function __construct(
        private BudgetCellsQuery $cells,
        private BudgetPairResolver $pairs,
        private BudgetTreeCalculator $tree,
        private PLCategoryFacade $categories,
        private FinanceFacade $finance,
        private ClockInterface $clock,
    ) {
    }

    public function plan(BudgetVersion $version, BudgetFillRequest $request): BudgetFillPlan
    {
        if (!$version->isEditable()) {
            throw new BudgetVersionNotEditableException($version->getStatus());
        }
        $companyId = $version->getCompanyId();
        $year = $version->getYear();
        $pair = $this->pairs->resolve($companyId, $request->projectDirectionId, $request->responsibilityCenterId)
            ?? throw new InvalidBudgetPairException();

        $flows = $this->leafFlows($companyId);
        if ([] === $request->categoryIds) {
            throw new InvalidBudgetInputException('Выберите статьи.');
        }
        foreach ($request->categoryIds as $categoryId) {
            if (!isset($flows[$categoryId])) {
                throw new InvalidBudgetInputException('Статья не найдена или в неё нельзя вводить план.');
            }
        }

        /** @var array<string, array<string, BudgetCellRow>> $existing plCategoryId → `Y-m` → ячейка */
        $existing = [];
        $planAmounts = [];
        foreach ($this->cells->fetch($companyId, $version->getId(), $pair->projectDirectionId, $pair->responsibilityCenterId) as $cell) {
            $existing[$cell->plCategoryId][$cell->month] = $cell;
            $planAmounts[$cell->month][$cell->plCategoryId] = $cell->amount;
        }

        $months = $this->months($request, $year);
        $targets = $this->targets($request, $companyId, $year, $months, $flows, $planAmounts, $pair->projectDirectionId, $pair->responsibilityCenterId);

        $cells = [];
        $overwrite = 0;
        $skipped = 0;
        foreach ($request->categoryIds as $categoryId) {
            foreach ($targets[$categoryId] ?? [] as $month => $amount) {
                $current = $existing[$categoryId][$month] ?? null;
                if (null !== $current && BudgetLineSource::SALES_PLAN->value === $current->source) {
                    ++$skipped;
                    continue;
                }
                $previous = null === $current ? '0.00' : $current->amount;
                if (0 === bccomp($amount, $previous, 2)) {
                    continue;
                }
                if (!BudgetAmount::isZero($previous)) {
                    ++$overwrite;
                }
                $cells[] = new BudgetFillCell($categoryId, $month, $amount, $previous, $current?->comment);
            }
        }

        return new BudgetFillPlan($cells, \count($cells), $overwrite, $skipped);
    }

    /**
     * @param list<string> $months
     * @param array<string, PLFlow> $flows
     * @param array<string, array<string, string>> $planAmounts `Y-m` → plCategoryId → план пары
     *
     * @return array<string, array<string, string>> plCategoryId → `Y-m` → новая сумма
     */
    private function targets(BudgetFillRequest $request, string $companyId, int $year, array $months, array $flows, array $planAmounts, string $project, string $center): array
    {
        $targets = [];
        switch ($request->helper) {
            case BudgetFillHelper::LAST_YEAR_FACT:
                $facts = $this->finance->getPlFactAmountsByMonth($companyId, $year - 1, $project, $center);
                foreach ($request->categoryIds as $categoryId) {
                    foreach ($months as $month) {
                        $targets[$categoryId][$month] = bcadd($facts[($year - 1).substr($month, 4)][$categoryId] ?? '0', '0', 2);
                    }
                }
                break;
            case BudgetFillHelper::AVERAGE:
                $count = $request->params['months'] ?? null;
                if (!\in_array($count, self::AVERAGE_MONTHS, true)) {
                    throw new InvalidBudgetInputException('Среднее — за 3, 6 или 12 месяцев.');
                }
                $closed = $this->lastClosedMonths($count);
                $facts = [];
                foreach (array_unique(array_map(static fn (string $m): int => (int) substr($m, 0, 4), $closed)) as $factYear) {
                    $facts += $this->finance->getPlFactAmountsByMonth($companyId, $factYear, $project, $center);
                }
                foreach ($request->categoryIds as $categoryId) {
                    $values = array_map(static fn (string $m): string => $facts[$m][$categoryId] ?? '0', $closed);
                    foreach ($months as $month) {
                        $targets[$categoryId][$month] = BudgetFillMath::average($values, $count);
                    }
                }
                break;
            case BudgetFillHelper::DISTRIBUTE:
                $amount = $this->stringParam($request, 'amount');
                if (!BudgetAmount::isValid($amount)) {
                    throw new InvalidBudgetInputException('Годовая сумма — число с точкой, до двух знаков после неё.');
                }
                foreach ($request->categoryIds as $categoryId) {
                    $parts = BudgetFillMath::distribute(BudgetFillMath::fromInput($amount, $flows[$categoryId]), 12);
                    $targets[$categoryId] = array_combine(BudgetTreeCalculator::months($year), $parts);
                }
                break;
            case BudgetFillHelper::COPY_RIGHT:
                $source = $this->stringParam($request, 'sourceMonth');
                if (!\in_array($source, BudgetTreeCalculator::months($year), true)) {
                    throw new InvalidBudgetInputException(sprintf('Исходный месяц должен быть в %d году.', $year));
                }
                foreach ($request->categoryIds as $categoryId) {
                    $value = bcadd($planAmounts[$source][$categoryId] ?? '0', '0', 2);
                    foreach (BudgetTreeCalculator::months($year) as $month) {
                        if ($month > $source) {
                            $targets[$categoryId][$month] = $value;
                        }
                    }
                }
                break;
            case BudgetFillHelper::PERCENT_OF:
                $percent = $this->stringParam($request, 'percent');
                if (!BudgetFillMath::isPercent($percent)) {
                    throw new InvalidBudgetInputException(sprintf('Процент — число от 0 до %s, до двух знаков после точки.', BudgetFillMath::MAX_PERCENT));
                }
                $baseId = $this->stringParam($request, 'baseCategoryId');
                if (\in_array($baseId, $request->categoryIds, true)) {
                    throw new InvalidBudgetInputException('Статья-база не может быть среди заполняемых статей.');
                }
                // База — без заполняемых статей: иначе итог-база, в который они входят, менялся бы от
                // самого помощника, и повтор того же запроса давал бы другие суммы.
                $baseAmounts = $planAmounts;
                foreach ($baseAmounts as $month => $byCategory) {
                    $baseAmounts[$month] = array_diff_key($byCategory, array_flip($request->categoryIds));
                }
                $base = $this->tree->calculate($companyId, $year, $baseAmounts)->byId()[$baseId] ?? null;
                if (null === $base) {
                    throw new InvalidBudgetInputException('Статья-база не найдена.');
                }
                foreach ($request->categoryIds as $categoryId) {
                    foreach ($months as $month) {
                        $targets[$categoryId][$month] = BudgetFillMath::signByFlow(BudgetFillMath::percentOf($base->values[$month], $percent), $flows[$categoryId]);
                    }
                }
                break;
        }

        return $targets;
    }

    /**
     * @return list<string>
     */
    private function months(BudgetFillRequest $request, int $year): array
    {
        $all = BudgetTreeCalculator::months($year);
        if ([] === $request->months) {
            return $all;
        }
        foreach ($request->months as $month) {
            if (!\in_array($month, $all, true)) {
                throw new InvalidBudgetInputException(sprintf('Месяц должен быть в %d году.', $year));
            }
        }

        return array_values(array_intersect($all, $request->months));
    }

    /**
     * N месяцев перед текущим (по Москве), от старого к новому.
     *
     * @return list<string>
     */
    private function lastClosedMonths(int $count): array
    {
        $month = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->modify('first day of this month');
        $result = [];
        for ($i = $count; $i >= 1; --$i) {
            $result[] = $month->modify(sprintf('-%d months', $i))->format('Y-m');
        }

        return $result;
    }

    /**
     * Листовые статьи, видимые в отчёте, и их направление.
     *
     * @return array<string, PLFlow>
     */
    private function leafFlows(string $companyId): array
    {
        $flows = [];
        foreach ($this->categories->getTreeByCompanyId($companyId) as $category) {
            if (PLCategoryType::LEAF_INPUT === $category->type && $category->isVisible) {
                $flows[$category->id] = $category->flow;
            }
        }

        return $flows;
    }

    private function stringParam(BudgetFillRequest $request, string $name): string
    {
        $value = $request->params[$name] ?? null;
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }
        if (!\is_string($value) || '' === trim($value)) {
            throw new InvalidBudgetInputException(sprintf('Не задан параметр %s.', $name));
        }

        return trim($value);
    }
}
