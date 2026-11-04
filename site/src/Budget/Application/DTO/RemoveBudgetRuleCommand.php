<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * Снять правило статьи в паре черновика: суммы остаются как ручные.
 */
final readonly class RemoveBudgetRuleCommand
{
    public function __construct(
        public string $companyId,
        public string $versionId,
        public string $projectDirectionId,
        public string $responsibilityCenterId,
        public string $plCategoryId,
    ) {
    }
}
