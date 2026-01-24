<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Entity\CommissionerReport;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCostTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: WbCostTypeRepository::class)]
#[ORM\Table(name: 'wildberries_commissioner_cost_types')]
#[ORM\UniqueConstraint(name: 'uniq_wb_commissioner_cost_type_company_code', columns: ['company_id', 'code'])]
#[ORM\Index(name: 'idx_wb_commissioner_cost_type_company', columns: ['company_id'])]
class WbCostType
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Company $company, string $code, string $title, ?\DateTimeImmutable $createdAt = null)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->code = $code;
        $this->title = $title;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
