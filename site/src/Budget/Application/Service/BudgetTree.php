<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

/**
 * Результат BudgetTreeCalculator: дерево ОПиУ в порядке отчёта без скрытых статей.
 */
final readonly class BudgetTree
{
    /**
     * @param list<string> $months `Y-m`, 12 месяцев года
     * @param list<BudgetTreeRow> $rows
     * @param list<string> $warnings листья без кода, ошибки формул
     */
    public function __construct(
        public array $months,
        public array $rows,
        public array $warnings,
    ) {
    }

    /**
     * @return array<string, BudgetTreeRow> id статьи → строка
     */
    public function byId(): array
    {
        $result = [];
        foreach ($this->rows as $row) {
            $result[$row->row->id] = $row;
        }

        return $result;
    }
}
