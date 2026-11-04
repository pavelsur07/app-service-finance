<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * Правило «X % от строки-базы» пары (BudgetRulesQuery).
 */
final readonly class BudgetRuleRow
{
    public function __construct(
        public string $plCategoryId,
        public string $projectDirectionId,
        public string $responsibilityCenterId,
        public string $baseCategoryId,
        public string $percent,
    ) {
    }
}
