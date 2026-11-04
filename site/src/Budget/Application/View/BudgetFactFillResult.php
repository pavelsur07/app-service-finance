<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

/**
 * Итог «Заполнить из факта»: `created` = false — вариант с этим ID уже был (повтор), ничего не
 * записано; `cells` и `pairs` — заполненные ячейки и пары, где был факт.
 */
final readonly class BudgetFactFillResult
{
    public function __construct(
        public string $versionId,
        public bool $created,
        public int $cells,
        public int $pairs,
    ) {
    }
}
