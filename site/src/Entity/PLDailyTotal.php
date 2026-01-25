<?php

namespace App\Entity;

use App\Company\Entity\ProjectDirection;
use App\Repository\PLDailyTotalRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PLDailyTotalRepository::class)]
#[ORM\Table(name: 'pl_daily_totals')]
#[ORM\UniqueConstraint(name: 'uniq_pl_daily_company_cat_date', columns: ['company_id', 'pl_category_id', 'date', 'project_direction_id'])]
#[ORM\Index(name: 'idx_pl_daily_company_date', columns: ['company_id', 'date'])]
#[ORM\Index(name: 'idx_pl_daily_company_cat_date', columns: ['company_id', 'pl_category_id', 'date', 'project_direction_id'])]
class PLDailyTotal
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

    #[ORM\ManyToOne(targetEntity: ProjectDirection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ProjectDirection $projectDirection;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amountIncome = '0';

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amountExpense = '0';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company, ProjectDirection $projectDirection, \DateTimeImmutable $date, ?PLCategory $category)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->projectDirection = $projectDirection;
        $this->date = $date;
        $this->plCategory = $category;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getProjectDirection(): ProjectDirection
    {
        return $this->projectDirection;
    }

    public function setProjectDirection(ProjectDirection $projectDirection): self
    {
        $this->projectDirection = $projectDirection;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

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
