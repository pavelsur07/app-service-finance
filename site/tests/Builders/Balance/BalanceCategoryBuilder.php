<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;

final class BalanceCategoryBuilder
{
    public const DEFAULT_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_COMPANY_ID = '22222222-2222-2222-2222-222222222222';

    private string $id;
    private string $companyId;
    private string $name = 'Тестовая категория';
    private BalanceCategoryType $type = BalanceCategoryType::ASSET;
    private ?BalanceCategory $parent = null;
    private ?string $code = null;
    private int $sortOrder = 0;
    private bool $isVisible = true;

    private function __construct()
    {
        $this->id = self::DEFAULT_ID;
        $this->companyId = self::DEFAULT_COMPANY_ID;
    }

    public static function aBalanceCategory(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('11111111-1111-1111-1111-%012d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withType(BalanceCategoryType $type): self
    {
        $clone = clone $this;
        $clone->type = $type;

        return $clone;
    }

    public function withParent(?BalanceCategory $parent): self
    {
        $clone = clone $this;
        $clone->parent = $parent;

        return $clone;
    }

    public function withCode(?string $code): self
    {
        $clone = clone $this;
        $clone->code = $code;

        return $clone;
    }

    public function withSortOrder(int $sortOrder): self
    {
        $clone = clone $this;
        $clone->sortOrder = $sortOrder;

        return $clone;
    }

    public function withIsVisible(bool $isVisible): self
    {
        $clone = clone $this;
        $clone->isVisible = $isVisible;

        return $clone;
    }

    public function asAsset(): self
    {
        return $this->withType(BalanceCategoryType::ASSET);
    }

    public function asLiability(): self
    {
        return $this->withType(BalanceCategoryType::LIABILITY);
    }

    public function asEquity(): self
    {
        return $this->withType(BalanceCategoryType::EQUITY);
    }

    public function build(): BalanceCategory
    {
        $category = new BalanceCategory($this->id, $this->companyId);
        $category->setName($this->name);
        $category->setType($this->type);
        $category->setCode($this->code);
        $category->setSortOrder($this->sortOrder);
        $category->setIsVisible($this->isVisible);

        if (null !== $this->parent) {
            $category->setParent($this->parent);
        }

        return $category;
    }
}
