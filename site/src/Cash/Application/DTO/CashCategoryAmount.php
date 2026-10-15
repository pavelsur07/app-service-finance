<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Модуль суммы операций ДДС по статье; `categoryId` = null — операции без статьи.
 */
final readonly class CashCategoryAmount
{
    public function __construct(
        public ?string $categoryId,
        public string $categoryName,
        public string $amountAbs,
    ) {
    }
}
