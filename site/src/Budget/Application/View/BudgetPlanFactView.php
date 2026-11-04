<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

final readonly class BudgetPlanFactView
{
    /**
     * @param list<string> $months `Y-m`, 12 месяцев года
     * @param list<BudgetPlanFactRow> $rows дерево ОПиУ в порядке отчёта, без скрытых статей
     * @param list<string> $warnings листья без кода, ошибки формул
     */
    public function __construct(
        public string $versionId,
        public int $year,
        public int $thresholdPercent,
        public array $months,
        public array $rows,
        public array $warnings,
    ) {
    }
}
