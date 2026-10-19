<?php

declare(strict_types=1);

namespace App\Tests\Builders\Ingestion;

use App\Ingestion\Entity\CategoryGroupRule;
use App\Ingestion\Enum\CategoryGroupRuleConfidence;
use App\Ingestion\Enum\IngestSource;

final class CategoryGroupRuleBuilder
{
    private int $priority = 1;
    private string $pattern = 'данные';
    private string $groupCode = 'other';
    private CategoryGroupRuleConfidence $confidence = CategoryGroupRuleConfidence::HIGH;
    private bool $active = true;

    public static function aRule(): self
    {
        return new self();
    }

    public function withPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function withPattern(string $pattern): self
    {
        $this->pattern = $pattern;

        return $this;
    }

    public function withGroupCode(string $groupCode): self
    {
        $this->groupCode = $groupCode;

        return $this;
    }

    public function withConfidence(CategoryGroupRuleConfidence $confidence): self
    {
        $this->confidence = $confidence;

        return $this;
    }

    public function inactive(): self
    {
        $this->active = false;

        return $this;
    }

    public function build(): CategoryGroupRule
    {
        return new CategoryGroupRule(IngestSource::OZON, $this->priority, $this->pattern, $this->groupCode, $this->confidence, $this->active);
    }
}
