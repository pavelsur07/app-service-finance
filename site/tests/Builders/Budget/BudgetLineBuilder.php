<?php

declare(strict_types=1);

namespace App\Tests\Builders\Budget;

use App\Budget\Entity\BudgetLine;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetLineSource;

final class BudgetLineBuilder
{
    public const DEFAULT_ID = '0193cccc-0000-7000-8000-0000000b1001';
    public const DEFAULT_PL_CATEGORY_ID = '0193cccc-0000-7000-8000-0000000b2001';
    public const DEFAULT_PROJECT_ID = '0193cccc-0000-7000-8000-0000000b3001';
    public const DEFAULT_CENTER_ID = '0193cccc-0000-7000-8000-0000000b4001';

    private string $id = self::DEFAULT_ID;
    private ?BudgetVersion $version = null;
    private string $plCategoryId = self::DEFAULT_PL_CATEGORY_ID;
    private string $month = '2027-01';
    private string $projectDirectionId = self::DEFAULT_PROJECT_ID;
    private string $responsibilityCenterId = self::DEFAULT_CENTER_ID;
    private string $amount = '1000.00';
    private BudgetLineSource $source = BudgetLineSource::MANUAL;
    private ?string $comment = null;

    public static function aLine(): self
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

    /**
     * @param string $month `Y-m`
     */
    public function forMonth(string $month): self
    {
        $clone = clone $this;
        $clone->month = $month;

        return $clone;
    }

    public function forPair(string $projectDirectionId, string $responsibilityCenterId): self
    {
        $clone = clone $this;
        $clone->projectDirectionId = $projectDirectionId;
        $clone->responsibilityCenterId = $responsibilityCenterId;

        return $clone;
    }

    public function withAmount(string $amount): self
    {
        $clone = clone $this;
        $clone->amount = $amount;

        return $clone;
    }

    public function withSource(BudgetLineSource $source): self
    {
        $clone = clone $this;
        $clone->source = $source;

        return $clone;
    }

    public function withComment(?string $comment): self
    {
        $clone = clone $this;
        $clone->comment = $comment;

        return $clone;
    }

    public function build(): BudgetLine
    {
        return new BudgetLine(
            $this->id,
            $this->version ?? BudgetVersionBuilder::aVersion()->build(),
            $this->plCategoryId,
            new \DateTimeImmutable($this->month.'-01'),
            $this->projectDirectionId,
            $this->responsibilityCenterId,
            $this->amount,
            $this->source,
            $this->comment,
            new \DateTimeImmutable('2026-12-01 10:00:00'),
        );
    }
}
