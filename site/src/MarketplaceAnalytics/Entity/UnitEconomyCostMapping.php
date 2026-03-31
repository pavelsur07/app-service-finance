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
    columns: ['company_id', 'marketplace', 'cost_category_id'],
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

    #[ORM\Column(type: 'string', length: 36)]
    private string $costCategoryId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $costCategoryName;

    #[ORM\Column(type: 'string', length: 50, enumType: UnitEconomyCostType::class)]
    private UnitEconomyCostType $unitEconomyCostType;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $companyId,
        MarketplaceType $marketplace,
        string $costCategoryId,
        string $costCategoryName,
        UnitEconomyCostType $unitEconomyCostType,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::notEmpty($costCategoryId);
        Assert::notEmpty($costCategoryName);

        $this->id                  = $id;
        $this->companyId           = $companyId;
        $this->marketplace         = $marketplace;
        $this->costCategoryId      = $costCategoryId;
        $this->costCategoryName    = $costCategoryName;
        $this->unitEconomyCostType = $unitEconomyCostType;
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
    public function getCostCategoryId(): string { return $this->costCategoryId; }
    public function getCostCategoryName(): string { return $this->costCategoryName; }
    public function getUnitEconomyCostType(): UnitEconomyCostType { return $this->unitEconomyCostType; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
