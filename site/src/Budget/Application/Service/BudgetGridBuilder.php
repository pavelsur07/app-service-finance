<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\View\BudgetGridRow;
use App\Budget\Application\View\BudgetGridView;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Infrastructure\Query\BudgetCellsQuery;
use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PlPreviewUnplacedReason;
use App\Finance\Enum\PLValueFormat;
use App\Finance\Facade\FinanceFacade;
use App\Finance\Facade\PLCategoryFacade;

/**
 * Сетка варианта: дерево ОПиУ × 12 месяцев + год. Листья — суммы плана как есть; итоги, формулы
 * и KPI — калькулятором отчёта ОПиУ (`FinanceFacade::calculatePlTree`), год — отдельной колонкой
 * (KPI года не равен сумме месяцев). Лист без кода отчёт не считает — в итоги он не входит, об
 * этом предупреждение. Только чтение.
 */
final readonly class BudgetGridBuilder
{
    private const TOTAL = 'total';

    public function __construct(
        private BudgetCellsQuery $cells,
        private FinanceFacade $finance,
        private PLCategoryFacade $categories,
    ) {
    }

    public function build(BudgetVersion $version, ?string $projectDirectionId, ?string $responsibilityCenterId): BudgetGridView
    {
        $companyId = $version->getCompanyId();
        $year = $version->getYear();
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $months[] = sprintf('%04d-%02d', $year, $month);
        }

        /** @var array<string, array<string, string>> $amounts month|total → categoryId → amount */
        $amounts = array_fill_keys([...$months, self::TOTAL], []);
        /** @var array<string, array<string, string>> $comments categoryId → month → comment */
        $comments = [];
        foreach ($this->cells->fetch($companyId, $version->getId(), $projectDirectionId, $responsibilityCenterId) as $cell) {
            $amounts[$cell->month][$cell->plCategoryId] = $cell->amount;
            $amounts[self::TOTAL][$cell->plCategoryId] = bcadd($amounts[self::TOTAL][$cell->plCategoryId] ?? '0', $cell->amount, 2);
            if (null !== $cell->comment) {
                $comments[$cell->plCategoryId][$cell->month] = $cell->comment;
            }
        }

        $columns = [];
        foreach ($amounts as $key => $byCategory) {
            $columns[] = new PlPreviewColumn((string) $key, $byCategory);
        }
        $tree = $this->finance->calculatePlTree(
            $companyId,
            new \DateTimeImmutable(sprintf('%04d-01-01', $year)),
            new \DateTimeImmutable(sprintf('%04d-12-31', $year)),
            $columns,
        );

        $categories = [];
        foreach ($this->categories->getTreeByCompanyId($companyId) as $category) {
            $categories[$category->id] = $category;
        }

        $rows = [];
        foreach ($tree->rows as $row) {
            $category = $categories[$row->id] ?? null;
            if (null === $category || !$category->isVisible) {
                continue;
            }
            $leaf = PLCategoryType::LEAF_INPUT === $row->type;
            $digits = PLValueFormat::MONEY === $row->format ? 2 : 4;
            $values = [];
            foreach ([...$months, self::TOTAL] as $key) {
                // Лист показывает сумму плана (и без кода, когда калькулятор её не берёт).
                $values[$key] = $leaf
                    ? bcadd($amounts[$key][$row->id] ?? '0', '0', 2)
                    : number_format($row->values[$key] ?? 0.0, $digits, '.', '');
            }
            $total = $values[self::TOTAL];
            unset($values[self::TOTAL]);
            $rows[] = new BudgetGridRow(
                id: $row->id,
                code: $row->code,
                name: $row->name,
                level: $row->level,
                type: $row->type->value,
                flow: strtolower($category->flow->value),
                format: $row->format->value,
                editable: $leaf,
                values: $values,
                total: $total,
                comments: $comments[$row->id] ?? [],
            );
        }

        $warnings = $tree->warnings;
        foreach ($tree->unplaced as $unplaced) {
            if (PlPreviewUnplacedReason::NO_CODE === $unplaced->reason) {
                $warnings[] = sprintf('Статья «%s» без кода: план по ней не попадает в итоги отчёта ОПиУ. Задайте код статьи в справочнике.', $unplaced->categoryName ?? $unplaced->categoryId);
            }
        }

        return new BudgetGridView($version->getId(), $year, $months, $rows, array_values(array_unique($warnings)));
    }
}
