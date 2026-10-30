<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Подсказка статьи для строки списка операций.
 */
final readonly class CashCategorySuggestionView
{
    public function __construct(
        public string $id,
        public string $categoryName,
        public int $confidencePercent,
        /** Сервис пометил пачку «показать человеку» (низкая уверенность, направление статьи). */
        public bool $needsReview,
    ) {
    }
}
