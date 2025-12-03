<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Entity;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: WildberriesReportDetailMappingRepository::class)]
#[ORM\Table(name: 'wildberries_report_detail_mappings')]
#[ORM\UniqueConstraint(name: 'uniq_wb_mapping_company_oper_doc_source', columns: ['company_id', 'supplier_oper_name', 'doc_type_name', 'source_field'])]
class WildberriesReportDetailMapping
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(name: 'supplier_oper_name', type: Types::STRING, length: 255, nullable: false)]
    private string $supplierOperName;

    #[ORM\Column(name: 'doc_type_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $docTypeName = null;

    #[ORM\Column(name: 'site_country', type: Types::STRING, length: 255, nullable: true)]
    private ?string $siteCountry = null;

    #[ORM\Column(name: 'source_field', type: Types::STRING, length: 64, nullable: false)]
    private string $sourceField;

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    #[ORM\JoinColumn(name: 'pl_category_id', referencedColumnName: 'id', nullable: false)]
    private PLCategory $plCategory;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'note', type: Types::STRING, length: 1024, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company)
    {
        Assert::uuid($id);

        $this->id = $id;
        $this->company = $company;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->isActive = true;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
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

    public function getSupplierOperName(): string
    {
        return $this->supplierOperName;
    }

    public function setSupplierOperName(string $supplierOperName): self
    {
        $this->supplierOperName = $supplierOperName;

        return $this;
    }

    public function getDocTypeName(): ?string
    {
        return $this->docTypeName;
    }

    public function setDocTypeName(?string $docTypeName): self
    {
        $this->docTypeName = $docTypeName;

        return $this;
    }

    public function getSiteCountry(): ?string
    {
        return $this->siteCountry;
    }

    public function setSiteCountry(?string $siteCountry): self
    {
        $this->siteCountry = $siteCountry;

        return $this;
    }

    public function getSourceField(): string
    {
        return $this->sourceField;
    }

    public function setSourceField(string $sourceField): self
    {
        $this->sourceField = $sourceField;

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

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
