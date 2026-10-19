<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use App\Ingestion\Enum\CategoryGroupRuleConfidence;

/**
 * Подсказка группы для типа начисления: какое правило сработало и насколько ему
 * верить.
 */
final readonly class CategoryGroupSuggestion
{
    public function __construct(
        public string $groupCode,
        public string $groupLabel,
        public CategoryGroupRuleConfidence $confidence,
        public string $ruleId,
    ) {
    }

    public function isHigh(): bool
    {
        return CategoryGroupRuleConfidence::HIGH === $this->confidence;
    }
}
