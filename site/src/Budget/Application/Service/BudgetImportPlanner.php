<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\DTO\BudgetPlanFile;
use App\Budget\Application\View\BudgetFillCell;
use App\Budget\Application\View\BudgetFillPlan;
use App\Budget\Application\View\BudgetImportPlan;
use App\Budget\Domain\BudgetAmount;
use App\Budget\Domain\BudgetFillMath;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Budget\Infrastructure\Query\BudgetCellsQuery;
use App\Budget\Infrastructure\Query\BudgetRulesQuery;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Facade\PLCategoryFacade;

/**
 * Ячейки пары черновика из файла плана (задача pl-budget, часть 4). Строка файла — статья по
 * «ID статьи» (есть ID — только по нему), без ID — по коду; загружаются только видимые листья ввода. Сумма файла — в знаке
 * ввода (BudgetFillMath::fromInput), пустая ячейка — 0: файл задаёт весь год статьи; не сумма в
 * загружаемой строке — отказ с адресом ячейки, в пропускаемой (итог, KPI) — не мешает. Правила те же,
 * что у помощников: только изменяемые ячейки, `sales_plan` и статьи с правилом — пропуск, комментарий сохраняется,
 * перезапись считается. Только расчёт; запись — ImportBudgetCellsAction.
 */
final readonly class BudgetImportPlanner
{
    private const int MAX_ERRORS = 10;
    private const array MONTH_NAMES = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

    public function __construct(
        private BudgetCellsQuery $cells,
        private BudgetRulesQuery $rules,
        private BudgetPairResolver $pairs,
        private PLCategoryFacade $categories,
    ) {
    }

    public function plan(BudgetVersion $version, string $projectDirectionId, string $responsibilityCenterId, BudgetPlanFile $file): BudgetImportPlan
    {
        if (!$version->isEditable()) {
            throw new BudgetVersionNotEditableException($version->getStatus());
        }
        $companyId = $version->getCompanyId();
        $year = $version->getYear();
        $pair = $this->pairs->resolve($companyId, $projectDirectionId, $responsibilityCenterId)
            ?? throw new InvalidBudgetPairException();

        $byId = [];
        $byCode = [];
        foreach ($this->categories->getTreeByCompanyId($companyId) as $category) {
            $byId[$category->id] = $category;
            if (null !== $category->code && '' !== $category->code) {
                $byCode[$category->code] = $category;
            }
        }

        $warnings = [];
        if (null !== $file->year && $file->year !== $year) {
            $warnings[] = sprintf('Файл выгружен из плана %d года — суммы запишутся в месяцы %d года по порядку.', $file->year, $year);
        }
        if (($file->projectDirectionId ?? $pair->projectDirectionId) !== $pair->projectDirectionId
            || ($file->responsibilityCenterId ?? $pair->responsibilityCenterId) !== $pair->responsibilityCenterId
            || (null === $file->projectDirectionId && null !== $file->year)) {
            $warnings[] = 'Файл выгружен для другой пары или для всей компании — суммы запишутся в выбранную пару.';
        }

        /** @var array<string, array<string, \App\Budget\Application\DTO\BudgetCellRow>> $existing */
        $existing = [];
        foreach ($this->cells->fetch($companyId, $version->getId(), $pair->projectDirectionId, $pair->responsibilityCenterId) as $cell) {
            $existing[$cell->plCategoryId][$cell->month] = $cell;
        }

        // Статьи с правилом «% от строки» считает BudgetRuleCalculator — загрузка их не трогает.
        $ruleTargets = $this->rules->targetsOfPair($companyId, $version->getId(), $pair->projectDirectionId, $pair->responsibilityCenterId);
        $ignored = [];
        $errors = [];
        $totals = 0;
        $seen = [];
        $cells = [];
        $overwrite = 0;
        $skipped = 0;
        foreach ($file->rows as $row) {
            // Есть ID — только по ID (строка удалённой статьи не должна попасть в чужую с тем же кодом).
            $category = null !== $row->plCategoryId
                ? $byId[$row->plCategoryId] ?? null
                : (null !== $row->code ? $byCode[$row->code] ?? null : null);
            if (null === $category) {
                $ignored[] = sprintf('строка %d «%s» — статья не найдена', $row->line, $row->name);
                continue;
            }
            if (PLCategoryType::LEAF_INPUT !== $category->type) {
                ++$totals;
                continue;
            }
            if (!$category->isVisible) {
                $ignored[] = sprintf('строка %d «%s» — статья скрыта', $row->line, $row->name);
                continue;
            }
            if (isset($seen[$category->id])) {
                throw new InvalidBudgetInputException(sprintf('Статья «%s» встречается в файле дважды (строки %d и %d).', $category->name, $seen[$category->id], $row->line));
            }
            $seen[$category->id] = $row->line;

            foreach ($row->amounts as $monthNumber => $input) {
                if (null === $input) {
                    $errors[] = sprintf('строка %d, %s — не число или больше двух знаков после запятой', $row->line, self::MONTH_NAMES[$monthNumber - 1] ?? $monthNumber);
                    continue;
                }
                $month = sprintf('%d-%02d', $year, $monthNumber);
                $amount = BudgetFillMath::fromInput($input, $category->flow);
                $current = $existing[$category->id][$month] ?? null;
                if (isset($ruleTargets[$category->id])) {
                    if (0 !== bccomp($amount, $current->amount ?? '0', 2)) {
                        ++$skipped;
                    }
                    continue;
                }
                if (null !== $current && BudgetLineSource::SALES_PLAN->value === $current->source) {
                    if (0 !== bccomp($amount, $current->amount, 2)) {
                        ++$skipped;
                    }
                    continue;
                }
                $previous = null === $current ? '0.00' : $current->amount;
                if (0 === bccomp($amount, $previous, 2)) {
                    continue;
                }
                if (!BudgetAmount::isZero($previous)) {
                    ++$overwrite;
                }
                $cells[] = new BudgetFillCell($category->id, $month, $amount, $previous, $current?->comment);
            }
        }
        if ([] !== $errors) {
            $shown = \array_slice($errors, 0, self::MAX_ERRORS);
            $more = \count($errors) - \count($shown);
            throw new InvalidBudgetInputException('Не удалось прочитать суммы: '.implode('; ', $shown).($more > 0 ? sprintf('; и ещё %d', $more) : '').'.');
        }
        if ($totals > 0) {
            $warnings[] = sprintf('Итоги и показатели не загружаются — они считаются из статей (строк: %d).', $totals);
        }

        return new BudgetImportPlan(new BudgetFillPlan($cells, \count($cells), $overwrite, $skipped), $warnings, $ignored);
    }
}
