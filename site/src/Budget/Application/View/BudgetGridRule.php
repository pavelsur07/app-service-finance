<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

final readonly class BudgetGridRule
{
    public function __construct(
        public string $percent,
        public string $baseCategoryId,
        public string $baseName,
    ) {
    }
}
