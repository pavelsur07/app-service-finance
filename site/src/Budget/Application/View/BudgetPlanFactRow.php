<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

use App\Budget\Domain\BudgetVariance;

/**
 * Строка план–факт: статья дерева ОПиУ и отклонения по 12 месяцам и году (`total`).
 */
final readonly class BudgetPlanFactRow
{
    /**
     * @param array<string, BudgetVariance> $cells `Y-m`|total → план, факт, отклонение
     */
    public function __construct(
        public string $id,
        public ?string $code,
        public string $name,
        public int $level,
        public string $type,
        public string $format,
        public array $cells,
    ) {
    }
}
