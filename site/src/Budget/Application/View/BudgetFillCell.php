<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

final readonly class BudgetFillCell
{
    /**
     * @param string $month `Y-m`
     */
    public function __construct(
        public string $plCategoryId,
        public string $month,
        public string $amount,
        public string $previous,
        public ?string $comment,
    ) {
    }
}
