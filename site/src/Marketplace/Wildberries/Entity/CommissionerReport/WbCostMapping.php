<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Entity\CommissionerReport;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCostMappingRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: WbCostMappingRepository::class)]
#[ORM\Table(name: 'wildberries_commissioner_cost_mappings')]
#[ORM\UniqueConstraint(name: 'uniq_wb_commissioner_cost_mapping_company_dimension', columns: ['company_id', 'dimension_value_id'])]
#[ORM\Index(name: 'idx_wb_commissioner_cost_mapping_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_wb_commissioner_cost_mapping_dimension', columns: ['dimension_value_id'])]
class WbCostMapping
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: WbDimensionValue::class)]
    #[ORM\JoinColumn(name: 'dimension_value_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WbDimensionValue $dimensionValue;

    #[ORM\ManyToOne(targetEntity: WbCostType::class)]
    #[ORM\JoinColumn(name: 'cost_type_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WbCostType $costType;

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    #[ORM\JoinColumn(name: 'pl_category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PLCategory $plCategory;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        WbDimensionValue $dimensionValue,
        WbCostType $costType,
        PLCategory $plCategory,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->dimensionValue = $dimensionValue;
        $this->costType = $costType;
        $this->plCategory = $plCategory;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? $this->createdAt;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getDimensionValue(): WbDimensionValue
    {
        return $this->dimensionValue;
    }

    public function setDimensionValue(WbDimensionValue $dimensionValue): self
    {
        $this->dimensionValue = $dimensionValue;

        return $this;
    }

    public function getCostType(): WbCostType
    {
        return $this->costType;
    }

    public function setCostType(WbCostType $costType): self
    {
        $this->costType = $costType;

        return $this;
    }

    public function getPlCategory(): PLCategory
    {
        return $this->plCategory;
    }

    public function setPlCategory(PLCategory $plCategory): self
    {
        $this->plCategory = $plCategory;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
