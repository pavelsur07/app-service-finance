<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Поступления и выбытия по названию статьи ДДС; decimal string.
 */
final readonly class CashCategoryTurnover
{
    public function __construct(
        public string $categoryName,
        public string $inflow,
        public string $outflow,
    ) {
    }
}
