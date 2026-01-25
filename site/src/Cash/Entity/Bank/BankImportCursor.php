<?php

namespace App\Cash\Entity\Bank;

use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'cash_bank_import_cursor')]
#[ORM\UniqueConstraint(name: 'uniq_bank_import_cursor_company_bank_account', columns: ['company_id', 'bank_code', 'account_number'])]
class BankImportCursor
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 32)]
    private string $bankCode;

    #[ORM\Column(length: 64)]
    private string $accountNumber;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastImportedDate = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company, string $bankCode, string $accountNumber)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->bankCode = $bankCode;
        $this->accountNumber = $accountNumber;
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

    public function getLastImportedDate(): ?\DateTimeImmutable
    {
        return $this->lastImportedDate;
    }

    public function setLastImportedDate(?\DateTimeImmutable $date): void
    {
        $this->lastImportedDate = $date;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
