<?php

namespace App\Cash\Entity\Bank;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bank_import_cursor')]
#[ORM\UniqueConstraint(name: 'uniq_company_bank_account', columns: ['company_id', 'bank_code', 'account_number'])]
#[ORM\HasLifecycleCallbacks]
class BankImportCursor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 32)]
    private string $bankCode;

    #[ORM\Column(length: 64)]
    private string $accountNumber;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastImportedDate = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
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

    public function getBankCode(): string
    {
        return $this->bankCode;
    }

    public function setBankCode(string $bankCode): self
    {
        $this->bankCode = $bankCode;

        return $this;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(string $accountNumber): self
    {
        $this->accountNumber = $accountNumber;

        return $this;
    }

    public function getLastImportedDate(): ?\DateTimeInterface
    {
        return $this->lastImportedDate;
    }

    public function setLastImportedDate(?\DateTimeInterface $lastImportedDate): self
    {
        $this->lastImportedDate = $lastImportedDate;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
