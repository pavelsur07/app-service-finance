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
        private string $costCategoryCode,
        private string $unitEconomyCostType,
        private bool $isSystem,
        private string $createdAt,
        private string $updatedAt,
    ) {}

    public static function fromEntity(UnitEconomyCostMapping $mapping): self
    {
        return new self(
            id: $mapping->getId(),
            companyId: $mapping->getCompanyId(),
            marketplace: $mapping->getMarketplace()->value,
            costCategoryCode: $mapping->getCostCategoryCode(),
            unitEconomyCostType: $mapping->getUnitEconomyCostType()->value,
            isSystem: $mapping->isSystem(),
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
            'cost_category_code' => $this->costCategoryCode,
            'unit_economy_cost_type' => $this->unitEconomyCostType,
            'is_system' => $this->isSystem,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
