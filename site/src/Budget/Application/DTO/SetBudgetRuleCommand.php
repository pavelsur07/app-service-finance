<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * Задать или изменить правило «X % от строки-базы» статьи в паре черновика.
 */
final readonly class SetBudgetRuleCommand
{
    public function __construct(
        public string $companyId,
        public string $versionId,
        public string $projectDirectionId,
        public string $responsibilityCenterId,
        public string $plCategoryId,
        public string $baseCategoryId,
        public string $percent,
    ) {
    }
}
