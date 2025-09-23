<?php

namespace App\Entity;

use App\Enum\CashDirection;
use App\Entity\ProjectDirection;
use App\Repository\CashTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashTransactionRepository::class)]
#[ORM\Table(name: 'cash_transaction')]
#[ORM\Index(name: 'idx_company_account_occurred', columns: ['company_id', 'money_account_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_company_occurred', columns: ['company_id', 'occurred_at'])]
class CashTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: MoneyAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private MoneyAccount $moneyAccount;

    #[ORM\ManyToOne(targetEntity: Counterparty::class)]
    private ?Counterparty $counterparty = null;

    #[ORM\ManyToOne(targetEntity: CashflowCategory::class)]
    private ?CashflowCategory $cashflowCategory = null;

    #[ORM\ManyToOne(targetEntity: ProjectDirection::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ProjectDirection $projectDirection = null;

    #[ORM\Column(enumType: CashDirection::class)]
    private CashDirection $direction;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $bookedAt;

    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $docType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $docNumber = null;

    #[ORM\Column(length: 128, nullable: true, unique: true)]
    private ?string $externalId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isTransfer = false;

    #[ORM\Column(type: 'json')]
    private array $rawData = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        MoneyAccount $account,
        CashDirection $direction,
        string $amount,
        string $currency,
        \DateTimeImmutable $occurredAt,
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->moneyAccount = $account;
        $this->direction = $direction;
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->occurredAt = $occurredAt;
        $this->bookedAt = $occurredAt;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getMoneyAccount(): MoneyAccount
    {
        return $this->moneyAccount;
    }

    public function setMoneyAccount(MoneyAccount $a): self
    {
        $this->moneyAccount = $a;

        return $this;
    }

    public function getCounterparty(): ?Counterparty
    {
        return $this->counterparty;
    }

    public function setCounterparty(?Counterparty $c): self
    {
        $this->counterparty = $c;

        return $this;
    }

    public function getCashflowCategory(): ?CashflowCategory
    {
        return $this->cashflowCategory;
    }

    public function setCashflowCategory(?CashflowCategory $c): self
    {
        $this->cashflowCategory = $c;

        return $this;
    }

    public function getProjectDirection(): ?ProjectDirection
    {
        return $this->projectDirection;
    }

    public function setProjectDirection(?ProjectDirection $projectDirection): self
    {
        $this->projectDirection = $projectDirection;

        return $this;
    }

    public function getDirection(): CashDirection
    {
        return $this->direction;
    }

    public function setDirection(CashDirection $d): self
    {
        $this->direction = $d;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $a): self
    {
        $this->amount = $a;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $c): self
    {
        $this->currency = strtoupper($c);

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $o): self
    {
        $this->occurredAt = $o;

        return $this;
    }

    public function getBookedAt(): \DateTimeImmutable
    {
        return $this->bookedAt;
    }

    public function setBookedAt(\DateTimeImmutable $b): self
    {
        $this->bookedAt = $b;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $d): self
    {
        $this->description = $d;

        return $this;
    }

    public function getDocType(): ?string
    {
        return $this->docType;
    }

    public function setDocType(?string $docType): self
    {
        $this->docType = $docType;

        return $this;
    }

    public function getDocNumber(): ?string
    {
        return $this->docNumber;
    }

    public function setDocNumber(?string $docNumber): self
    {
        $this->docNumber = $docNumber;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $e): self
    {
        $this->externalId = $e;

        return $this;
    }

    public function isTransfer(): bool
    {
        return $this->isTransfer;
    }

    public function setIsTransfer(bool $isTransfer): self
    {
        $this->isTransfer = $isTransfer;

        return $this;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function setRawData(array $rawData): self
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $u): self
    {
        $this->updatedAt = $u;

        return $this;
    }
}
