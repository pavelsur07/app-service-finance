<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\DTO\PLCategoryDTO;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PlPreviewUnplacedReason;
use App\Finance\Enum\PLValueFormat;
use App\Finance\Facade\FinanceFacade;
use App\Finance\Facade\PLCategoryFacade;

/**
 * Дерево ОПиУ года из сумм по статьям и месяцам (план или факт) — тем же калькулятором, что
 * отчёт ОПиУ: 12 месяцев и колонка `total` (KPI года не равен сумме месяцев).
 *
 * Лист показывает свою сумму как есть (и без кода, хотя в итоги отчёта она не попадает);
 * итоговые строки и показатели — значения калькулятора: деньги с двумя знаками, прочие
 * форматы — с четырьмя. Скрытые в отчёте статьи пропускаются.
 */
final readonly class BudgetTreeCalculator
{
    public const string TOTAL = 'total';

    public function __construct(
        private FinanceFacade $finance,
        private PLCategoryFacade $categories,
    ) {
    }

    /**
     * @param array<string, array<string, string>> $amounts `Y-m` → plCategoryId → сумма в знаке отчёта
     */
    public function calculate(string $companyId, int $year, array $amounts): BudgetTree
    {
        $months = self::months($year);
        /** @var array<string, array<string, string>> $columns `Y-m`|total → plCategoryId → сумма */
        $columns = array_fill_keys([...$months, self::TOTAL], []);
        foreach ($amounts as $month => $byCategory) {
            if (!isset($columns[$month]) || self::TOTAL === $month) {
                continue;
            }
            foreach ($byCategory as $categoryId => $amount) {
                $columns[$month][$categoryId] = $amount;
                $columns[self::TOTAL][$categoryId] = bcadd($columns[self::TOTAL][$categoryId] ?? '0', $amount, 2);
            }
        }

        $previewColumns = [];
        foreach ($columns as $key => $byCategory) {
            $previewColumns[] = new PlPreviewColumn((string) $key, $byCategory);
        }
        $tree = $this->finance->calculatePlTree(
            $companyId,
            new \DateTimeImmutable(sprintf('%04d-01-01', $year)),
            new \DateTimeImmutable(sprintf('%04d-12-31', $year)),
            $previewColumns,
        );

        /** @var array<string, PLCategoryDTO> $categories */
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
            foreach (array_keys($columns) as $key) {
                $values[$key] = $leaf
                    ? bcadd($columns[$key][$row->id] ?? '0', '0', 2)
                    : number_format($row->values[$key] ?? 0.0, $digits, '.', '');
            }
            $rows[] = new BudgetTreeRow($row, $category, $values);
        }

        $warnings = $tree->warnings;
        foreach ($tree->unplaced as $unplaced) {
            if (PlPreviewUnplacedReason::NO_CODE === $unplaced->reason) {
                $warnings[] = sprintf('Статья «%s» без кода: план по ней не попадает в итоги отчёта ОПиУ. Задайте код статьи в справочнике.', $unplaced->categoryName ?? $unplaced->categoryId);
            }
        }

        return new BudgetTree($months, $rows, array_values(array_unique($warnings)));
    }

    /**
     * @return list<string>
     */
    public static function months(int $year): array
    {
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $months[] = sprintf('%04d-%02d', $year, $month);
        }

        return $months;
    }
}
