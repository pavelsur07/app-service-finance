<?php

declare(strict_types=1);

namespace App\Tests\Builders\Budget;

use App\Budget\Entity\BudgetRule;
use App\Budget\Entity\BudgetVersion;

final class BudgetRuleBuilder
{
    public const DEFAULT_ID = '0193cccc-0000-7000-8000-0000000b5001';
    public const DEFAULT_PL_CATEGORY_ID = BudgetLineBuilder::DEFAULT_PL_CATEGORY_ID;
    public const DEFAULT_BASE_CATEGORY_ID = '0193cccc-0000-7000-8000-0000000b2002';

    private string $id = self::DEFAULT_ID;
    private ?BudgetVersion $version = null;
    private string $plCategoryId = self::DEFAULT_PL_CATEGORY_ID;
    private string $projectDirectionId = BudgetLineBuilder::DEFAULT_PROJECT_ID;
    private string $responsibilityCenterId = BudgetLineBuilder::DEFAULT_CENTER_ID;
    private string $baseCategoryId = self::DEFAULT_BASE_CATEGORY_ID;
    private string $percent = '10.00';

    public static function aRule(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function inVersion(BudgetVersion $version): self
    {
        $clone = clone $this;
        $clone->version = $version;

        return $clone;
    }

    public function forCategory(string $plCategoryId): self
    {
        $clone = clone $this;
        $clone->plCategoryId = $plCategoryId;

        return $clone;
    }

    public function forPair(string $projectDirectionId, string $responsibilityCenterId): self
    {
        $clone = clone $this;
        $clone->projectDirectionId = $projectDirectionId;
        $clone->responsibilityCenterId = $responsibilityCenterId;

        return $clone;
    }

    public function fromBase(string $baseCategoryId): self
    {
        $clone = clone $this;
        $clone->baseCategoryId = $baseCategoryId;

        return $clone;
    }

    public function withPercent(string $percent): self
    {
        $clone = clone $this;
        $clone->percent = $percent;

        return $clone;
    }

    public function build(): BudgetRule
    {
        return new BudgetRule(
            $this->id,
            $this->version ?? BudgetVersionBuilder::aVersion()->build(),
            $this->plCategoryId,
            $this->projectDirectionId,
            $this->responsibilityCenterId,
            $this->baseCategoryId,
            $this->percent,
            new \DateTimeImmutable('2026-12-01 10:00:00'),
        );
    }
}
