<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Entity;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: UnitEconomyCostMappingRepository::class)]
#[ORM\Table(name: 'unit_economy_cost_mappings')]
#[ORM\UniqueConstraint(
    name: 'uniq_cost_mapping',
    columns: ['company_id', 'marketplace', 'cost_category_code'],
)]
#[ORM\Index(columns: ['company_id'], name: 'idx_cost_mapping_company')]
#[ORM\Index(columns: ['company_id', 'marketplace'], name: 'idx_cost_mapping_company_marketplace')]
class UnitEconomyCostMapping
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 50, enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'string', length: 50)]
    private string $costCategoryCode;

    #[ORM\Column(type: 'string', length: 50, enumType: UnitEconomyCostType::class)]
    private UnitEconomyCostType $unitEconomyCostType;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSystem;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $companyId,
        MarketplaceType $marketplace,
        string $costCategoryCode,
        UnitEconomyCostType $unitEconomyCostType,
        bool $isSystem = false,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::notEmpty($costCategoryCode);
        Assert::maxLength($costCategoryCode, 50);

        $this->id                  = $id;
        $this->companyId           = $companyId;
        $this->marketplace         = $marketplace;
        $this->costCategoryCode    = $costCategoryCode;
        $this->unitEconomyCostType = $unitEconomyCostType;
        $this->isSystem            = $isSystem;
        $this->createdAt           = new \DateTimeImmutable();
        $this->updatedAt           = new \DateTimeImmutable();
    }

    public function remapTo(UnitEconomyCostType $newType): void
    {
        $this->unitEconomyCostType = $newType;
        $this->updatedAt           = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getCostCategoryCode(): string { return $this->costCategoryCode; }
    public function getUnitEconomyCostType(): UnitEconomyCostType { return $this->unitEconomyCostType; }
    public function isSystem(): bool { return $this->isSystem; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
