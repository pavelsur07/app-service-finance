<?php

declare(strict_types=1);

namespace App\Loan\Entity;

use App\Loan\Repository\LoanPaymentScheduleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: LoanPaymentScheduleRepository::class)]
#[ORM\Table(name: 'finance_loan_payment_schedule')]
#[ORM\Index(name: 'idx_finance_loan_payment_schedule_loan', columns: ['loan_id'])]
#[ORM\Index(name: 'idx_finance_loan_payment_schedule_due_date', columns: ['due_date'])]
class LoanPaymentSchedule
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Loan::class, inversedBy: 'paymentScheduleItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Loan $loan = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dueDate;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $totalPaymentAmount;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $principalPart;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $interestPart;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $feePart;

    #[ORM\Column(type: 'boolean')]
    private bool $isPaid = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Loan $loan,
        \DateTimeImmutable $dueDate,
        string $totalPaymentAmount,
        string $principalPart,
        string $interestPart,
        string $feePart,
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->loan = $loan;
        $this->dueDate = $dueDate;
        $this->totalPaymentAmount = $totalPaymentAmount;
        $this->principalPart = $principalPart;
        $this->interestPart = $interestPart;
        $this->feePart = $feePart;
        $this->isPaid = false;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLoan(): ?Loan
    {
        return $this->loan;
    }

    public function setLoan(?Loan $loan): self
    {
        $this->loan = $loan;

        return $this;
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(\DateTimeImmutable $dueDate): self
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getTotalPaymentAmount(): string
    {
        return $this->totalPaymentAmount;
    }

    public function setTotalPaymentAmount(string $totalPaymentAmount): self
    {
        $this->totalPaymentAmount = $totalPaymentAmount;

        return $this;
    }

    public function getPrincipalPart(): string
    {
        return $this->principalPart;
    }

    public function setPrincipalPart(string $principalPart): self
    {
        $this->principalPart = $principalPart;

        return $this;
    }

    public function getInterestPart(): string
    {
        return $this->interestPart;
    }

    public function setInterestPart(string $interestPart): self
    {
        $this->interestPart = $interestPart;

        return $this;
    }

    public function getFeePart(): string
    {
        return $this->feePart;
    }

    public function setFeePart(string $feePart): self
    {
        $this->feePart = $feePart;

        return $this;
    }

    public function isPaid(): bool
    {
        return $this->isPaid;
    }

    public function setIsPaid(bool $isPaid): self
    {
        $this->isPaid = $isPaid;

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
