<?php

namespace App\Entity;

use App\Company\Entity\Company;
use App\Repository\PLMonthlySnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PLMonthlySnapshotRepository::class)]
#[ORM\Table(name: 'pl_monthly_snapshots')]
#[ORM\UniqueConstraint(name: 'uniq_pl_monthly_company_cat_period', columns: ['company_id', 'pl_category_id', 'period'])]
#[ORM\Index(name: 'idx_pl_monthly_company_period', columns: ['company_id', 'period'])]
class PLMonthlySnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PLCategory $plCategory = null;

    #[ORM\Column(length: 7)]
    private string $period;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amountIncome = '0';

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amountExpense = '0';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company, string $period, ?PLCategory $category)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->period = $period;
        $this->plCategory = $category;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getPlCategory(): ?PLCategory
    {
        return $this->plCategory;
    }

    public function setPlCategory(?PLCategory $category): self
    {
        $this->plCategory = $category;

        return $this;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function setPeriod(string $period): self
    {
        $this->period = $period;

        return $this;
    }

    public function getAmountIncome(): string
    {
        return $this->amountIncome;
    }

    public function setAmountIncome(string $amount): self
    {
        $this->amountIncome = $amount;

        return $this;
    }

    public function getAmountExpense(): string
    {
        return $this->amountExpense;
    }

    public function setAmountExpense(string $amount): self
    {
        $this->amountExpense = $amount;

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
