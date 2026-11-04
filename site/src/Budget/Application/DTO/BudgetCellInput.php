<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * Ячейка плана с экрана: `month` — `Y-m`, `amount` — строка NUMERIC(18,2) в знаке отчёта ОПиУ.
 */
final readonly class BudgetCellInput
{
    public function __construct(
        public string $plCategoryId,
        public string $month,
        public string $projectDirectionId,
        public string $responsibilityCenterId,
        public string $amount,
        public ?string $comment = null,
    ) {
    }
}
