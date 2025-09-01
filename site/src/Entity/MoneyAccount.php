<?php

namespace App\Entity;

use App\Enum\MoneyAccountType;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`money_account`')]
#[ORM\UniqueConstraint(name: 'uniq_company_name', columns: ['company_id', 'name'])]
#[ORM\Index(name: 'idx_company_type', columns: ['company_id', 'type'])]
#[ORM\Index(name: 'idx_company_currency_active', columns: ['company_id', 'currency', 'is_active'])]
class MoneyAccount
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(enumType: MoneyAccountType::class)]
    private MoneyAccountType $type;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean')]
    private bool $isDefault = false;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $openingBalance = '0.00';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $openingBalanceDate;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $currentBalance = '0.00';

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 100;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    // Bank fields
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $bankName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $accountNumber = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $corrAccount = null;

    // Cash fields
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $responsiblePerson = null;

    // E-wallet fields
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $walletId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    public function __construct(string $id, Company $company, MoneyAccountType $type, string $name, string $currency)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->type = $type;
        $this->name = $name;
        $this->currency = strtoupper($currency);
        $this->openingBalanceDate = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }
    public function getType(): MoneyAccountType { return $this->type; }
    public function setType(MoneyAccountType $type): self { $this->type = $type; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): self { $this->isDefault = $isDefault; return $this; }
    public function getOpeningBalance(): string { return $this->openingBalance; }
    public function setOpeningBalance(string $openingBalance): self { $this->openingBalance = $openingBalance; return $this; }
    public function getOpeningBalanceDate(): \DateTimeImmutable { return $this->openingBalanceDate; }
    public function setOpeningBalanceDate(\DateTimeImmutable $openingBalanceDate): self { $this->openingBalanceDate = $openingBalanceDate; return $this; }
    public function getCurrentBalance(): string { return $this->currentBalance; }
    public function setCurrentBalance(string $currentBalance): self { $this->currentBalance = $currentBalance; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    public function getBankName(): ?string { return $this->bankName; }
    public function setBankName(?string $bankName): self { $this->bankName = $bankName; return $this; }
    public function getAccountNumber(): ?string { return $this->accountNumber; }
    public function setAccountNumber(?string $accountNumber): self { $this->accountNumber = $accountNumber; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $this->iban = $iban; return $this; }
    public function getBic(): ?string { return $this->bic; }
    public function setBic(?string $bic): self { $this->bic = $bic; return $this; }
    public function getCorrAccount(): ?string { return $this->corrAccount; }
    public function setCorrAccount(?string $corrAccount): self { $this->corrAccount = $corrAccount; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }
    public function getResponsiblePerson(): ?string { return $this->responsiblePerson; }
    public function setResponsiblePerson(?string $responsiblePerson): self { $this->responsiblePerson = $responsiblePerson; return $this; }

    public function getProvider(): ?string { return $this->provider; }
    public function setProvider(?string $provider): self { $this->provider = $provider; return $this; }
    public function getWalletId(): ?string { return $this->walletId; }
    public function setWalletId(?string $walletId): self { $this->walletId = $walletId; return $this; }

    public function getMeta(): ?array { return $this->meta; }
    public function setMeta(?array $meta): self { $this->meta = $meta; return $this; }
}
