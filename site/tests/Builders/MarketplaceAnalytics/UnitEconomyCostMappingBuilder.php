<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAnalytics;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;

final class UnitEconomyCostMappingBuilder
{
    public const DEFAULT_ID          = '55555555-5555-5555-5555-555555555555';
    public const DEFAULT_COMPANY_ID  = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_CATEGORY_ID = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private string $costCategoryId = self::DEFAULT_CATEGORY_ID;
    private string $costCategoryName = 'Default Category';
    private UnitEconomyCostType $unitEconomyCostType = UnitEconomyCostType::LOGISTICS_TO;

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
        $clone->costCategoryId = sprintf('cccccccc-cccc-cccc-cccc-%012d', $index);
        $clone->costCategoryName = sprintf('Category %d', $index);

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

    public function withCostCategoryId(string $costCategoryId): self
    {
        $clone = clone $this;
        $clone->costCategoryId = $costCategoryId;

        return $clone;
    }

    public function withCostCategoryName(string $costCategoryName): self
    {
        $clone = clone $this;
        $clone->costCategoryName = $costCategoryName;

        return $clone;
    }

    public function withUnitEconomyCostType(UnitEconomyCostType $unitEconomyCostType): self
    {
        $clone = clone $this;
        $clone->unitEconomyCostType = $unitEconomyCostType;

        return $clone;
    }

    public function build(): UnitEconomyCostMapping
    {
        return new UnitEconomyCostMapping(
            id: $this->id,
            companyId: $this->companyId,
            marketplace: $this->marketplace,
            costCategoryId: $this->costCategoryId,
            costCategoryName: $this->costCategoryName,
            unitEconomyCostType: $this->unitEconomyCostType,
        );
    }
}
