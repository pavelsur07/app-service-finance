<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;

final readonly class CostMappingResponse
{
    public function __construct(
        private string $id,
        private string $companyId,
        private string $marketplace,
        private string $costCategoryId,
        private string $costCategoryName,
        private string $unitEconomyCostType,
        private string $createdAt,
        private string $updatedAt,
    ) {}

    public static function fromEntity(UnitEconomyCostMapping $mapping): self
    {
        return new self(
            id: $mapping->getId(),
            companyId: $mapping->getCompanyId(),
            marketplace: $mapping->getMarketplace()->value,
            costCategoryId: $mapping->getCostCategoryId(),
            costCategoryName: $mapping->getCostCategoryName(),
            unitEconomyCostType: $mapping->getUnitEconomyCostType()->value,
            createdAt: $mapping->getCreatedAt()->format(\DATE_ATOM),
            updatedAt: $mapping->getUpdatedAt()->format(\DATE_ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->companyId,
            'marketplace' => $this->marketplace,
            'cost_category_id' => $this->costCategoryId,
            'cost_category_name' => $this->costCategoryName,
            'unit_economy_cost_type' => $this->unitEconomyCostType,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
