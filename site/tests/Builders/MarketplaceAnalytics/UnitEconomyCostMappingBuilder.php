<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAnalytics;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;

final class UnitEconomyCostMappingBuilder
{
    public const DEFAULT_ID         = '55555555-5555-5555-5555-555555555555';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private string $costCategoryCode = 'logistics';
    private UnitEconomyCostType $unitEconomyCostType = UnitEconomyCostType::LOGISTICS_TO;
    private bool $isSystem = false;

    private function __construct()
    {
    }

    public static function aMapping(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('55555555-5555-5555-5555-%012d', $index);
        $clone->costCategoryCode = sprintf('cost_cat_%d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withCostCategoryCode(string $costCategoryCode): self
    {
        $clone = clone $this;
        $clone->costCategoryCode = $costCategoryCode;

        return $clone;
    }

    public function withUnitEconomyCostType(UnitEconomyCostType $unitEconomyCostType): self
    {
        $clone = clone $this;
        $clone->unitEconomyCostType = $unitEconomyCostType;

        return $clone;
    }

    public function asSystem(): self
    {
        $clone = clone $this;
        $clone->isSystem = true;

        return $clone;
    }

    public function asCustom(): self
    {
        $clone = clone $this;
        $clone->isSystem = false;

        return $clone;
    }

    public function build(): UnitEconomyCostMapping
    {
        return new UnitEconomyCostMapping(
            id: $this->id,
            companyId: $this->companyId,
            marketplace: $this->marketplace,
            costCategoryCode: $this->costCategoryCode,
            unitEconomyCostType: $this->unitEconomyCostType,
            isSystem: $this->isSystem,
        );
    }
}
