<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

final readonly class BudgetCellRow
{
    /**
     * @param string $month `Y-m`
     */
    public function __construct(
        public string $plCategoryId,
        public string $month,
        public string $amount,
        public ?string $comment,
    ) {
    }
}
