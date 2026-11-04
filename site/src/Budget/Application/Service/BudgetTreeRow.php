<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\DTO\PLCategoryDTO;

final readonly class BudgetTreeRow
{
    /**
     * @param array<string, string> $values `Y-m`|total → значение строкой
     */
    public function __construct(
        public PlPreviewRow $row,
        public PLCategoryDTO $category,
        public array $values,
    ) {
    }
}
