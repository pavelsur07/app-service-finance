<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Entity\BalanceCategoryLink;
use App\Balance\Enum\BalanceLinkSourceType;

final class BalanceCategoryLinkBuilder
{
    public const DEFAULT_ID = '33333333-3333-3333-3333-333333333333';
    public const DEFAULT_COMPANY_ID = '22222222-2222-2222-2222-222222222222';

    private string $id;
    private string $companyId;
    private BalanceCategory $category;
    private BalanceLinkSourceType $sourceType = BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL;
    private ?string $sourceId = null;
    private int $sign = 1;
    private int $position = 0;

    private function __construct()
    {
        $this->id = self::DEFAULT_ID;
        $this->companyId = self::DEFAULT_COMPANY_ID;
        $this->category = BalanceCategoryBuilder::aBalanceCategory()->build();
    }

    public static function aBalanceCategoryLink(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withCategory(BalanceCategory $category): self
    {
        $clone = clone $this;
        $clone->category = $category;

        return $clone;
    }

    public function withSourceType(BalanceLinkSourceType $sourceType): self
    {
        $clone = clone $this;
        $clone->sourceType = $sourceType;

        return $clone;
    }

    public function withSourceId(?string $sourceId): self
    {
        $clone = clone $this;
        $clone->sourceId = $sourceId;

        return $clone;
    }

    public function withSign(int $sign): self
    {
        $clone = clone $this;
        $clone->sign = $sign;

        return $clone;
    }

    public function withPosition(int $position): self
    {
        $clone = clone $this;
        $clone->position = $position;

        return $clone;
    }

    public function build(): BalanceCategoryLink
    {
        $link = new BalanceCategoryLink($this->id, $this->companyId, $this->category);
        $link->setSourceType($this->sourceType);
        $link->setSourceId($this->sourceId);
        $link->setSign($this->sign);
        $link->setPosition($this->position);

        return $link;
    }
}
