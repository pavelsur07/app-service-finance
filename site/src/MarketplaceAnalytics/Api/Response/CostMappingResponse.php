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
        private string $unitEconomyCostType,
        private string $unitEconomyCostTypeLabel,
        private string $costCategoryId,
        private string $costCategoryName,
        private string $createdAt,
        private string $updatedAt,
    ) {}

    public static function fromEntity(UnitEconomyCostMapping $mapping): self
    {
        return new self(
            id: $mapping->getId(),
            companyId: $mapping->getCompanyId(),
            marketplace: $mapping->getMarketplace()->value,
            unitEconomyCostType: $mapping->getUnitEconomyCostType()->value,
            unitEconomyCostTypeLabel: $mapping->getUnitEconomyCostType()->getLabel(),
            costCategoryId: $mapping->getCostCategoryId(),
            costCategoryName: $mapping->getCostCategoryName(),
            createdAt: $mapping->getCreatedAt()->format(\DATE_ATOM),
            updatedAt: $mapping->getUpdatedAt()->format(\DATE_ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id'                        => $this->id,
            'company_id'                => $this->companyId,
            'marketplace'               => $this->marketplace,
            'unit_economy_cost_type'    => $this->unitEconomyCostType,
            'unit_economy_cost_type_label' => $this->unitEconomyCostTypeLabel,
            'cost_category_id'          => $this->costCategoryId,
            'cost_category_name'        => $this->costCategoryName,
            'created_at'                => $this->createdAt,
            'updated_at'                => $this->updatedAt,
        ];
    }
}
