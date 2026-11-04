<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

final readonly class BudgetGridView
{
    /**
     * @param list<string> $months `Y-m`, 12 месяцев года
     * @param list<BudgetGridRow> $rows дерево ОПиУ в порядке и составе отчёта
     * @param list<string> $warnings листья без кода, ошибки формул дерева
     */
    public function __construct(
        public string $versionId,
        public int $year,
        public array $months,
        public array $rows,
        public array $warnings,
    ) {
    }
}
