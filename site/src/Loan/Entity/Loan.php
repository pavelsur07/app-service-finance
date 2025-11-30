<?php

declare(strict_types=1);

namespace App\Loan\Entity;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Loan\Repository\LoanRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: LoanRepository::class)]
#[ORM\Table(name: 'finance_loan')]
#[ORM\Index(name: 'idx_finance_loan_company_status', columns: ['company_id', 'status'])]
class Loan
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lenderName = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $principalAmount;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $remainingPrincipal;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 4, nullable: true)]
    private ?string $interestRate = null;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $endDate = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $paymentDayOfMonth = null;

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    private ?PLCategory $plCategoryInterest = null;

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    private ?PLCategory $plCategoryFee = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $includePrincipalInPnl = false;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, LoanPaymentSchedule>
     */
    #[ORM\OneToMany(mappedBy: 'loan', targetEntity: LoanPaymentSchedule::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $paymentScheduleItems;

    public function __construct(Company $company, string $name, string $principalAmount, DateTimeImmutable $startDate)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->company = $company;
        $this->name = $name;
        $this->principalAmount = $principalAmount;
        $this->remainingPrincipal = $principalAmount;
        $this->startDate = $startDate;
        $this->status = 'active';
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->paymentScheduleItems = new ArrayCollection();
    }

    public function getId(): string
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getLenderName(): ?string
    {
        return $this->lenderName;
    }

    public function setLenderName(?string $lenderName): self
    {
        $this->lenderName = $lenderName;

        return $this;
    }

    public function getPrincipalAmount(): string
    {
        return $this->principalAmount;
    }

    public function setPrincipalAmount(string $principalAmount): self
    {
        $this->principalAmount = $principalAmount;

        return $this;
    }

    public function getRemainingPrincipal(): string
    {
        return $this->remainingPrincipal;
    }

    public function setRemainingPrincipal(string $remainingPrincipal): self
    {
        $this->remainingPrincipal = $remainingPrincipal;

        return $this;
    }

    public function getInterestRate(): ?string
    {
        return $this->interestRate;
    }

    public function setInterestRate(?string $interestRate): self
    {
        $this->interestRate = $interestRate;

        return $this;
    }

    public function getStartDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getPaymentDayOfMonth(): ?int
    {
        return $this->paymentDayOfMonth;
    }

    public function setPaymentDayOfMonth(?int $paymentDayOfMonth): self
    {
        $this->paymentDayOfMonth = $paymentDayOfMonth;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return Collection<int, LoanPaymentSchedule>
     */
    public function getPaymentScheduleItems(): Collection
    {
        return $this->paymentScheduleItems;
    }

    public function addPaymentScheduleItem(LoanPaymentSchedule $schedule): self
    {
        if (!$this->paymentScheduleItems->contains($schedule)) {
            $this->paymentScheduleItems->add($schedule);
            $schedule->setLoan($this);
        }

        return $this;
    }

    public function removePaymentScheduleItem(LoanPaymentSchedule $schedule): self
    {
        if ($this->paymentScheduleItems->removeElement($schedule)) {
            if ($schedule->getLoan() === $this) {
                $schedule->setLoan(null);
            }
        }

        return $this;
    }

    public function getPlCategoryInterest(): ?PLCategory
    {
        return $this->plCategoryInterest;
    }

    public function setPlCategoryInterest(?PLCategory $plCategoryInterest): self
    {
        $this->plCategoryInterest = $plCategoryInterest;

        return $this;
    }

    public function getPlCategoryFee(): ?PLCategory
    {
        return $this->plCategoryFee;
    }

    public function setPlCategoryFee(?PLCategory $plCategoryFee): self
    {
        $this->plCategoryFee = $plCategoryFee;

        return $this;
    }

    public function isIncludePrincipalInPnl(): bool
    {
        return $this->includePrincipalInPnl;
    }

    public function setIncludePrincipalInPnl(bool $includePrincipalInPnl): self
    {
        $this->includePrincipalInPnl = $includePrincipalInPnl;

        return $this;
    }
}
