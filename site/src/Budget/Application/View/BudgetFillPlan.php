<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

/**
 * Итог помощника: только изменяемые ячейки (новая сумма ≠ текущей). `overwrite` — сколько из них
 * уже были заполнены; `skipped` — ячейки с источником «план продаж», которые помощник не трогает.
 */
final readonly class BudgetFillPlan
{
    /**
     * @param list<BudgetFillCell> $cells
     */
    public function __construct(
        public array $cells,
        public int $changed,
        public int $overwrite,
        public int $skipped,
    ) {
    }
}
