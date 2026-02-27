<?php

namespace App\Cash\Entity\PaymentPlan;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Company\Entity\Company;
use App\Entity\Counterparty;
use App\Enum\PaymentPlanStatus;
use App\Cash\Enum\PaymentPlan\PaymentPlanSource;
use App\Enum\PaymentPlanType;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PaymentPlanRepository::class)]
#[ORM\Table(name: 'payment_plan')]
#[ORM\Index(name: 'idx_payment_plan_company_expected_at', columns: ['company_id', 'expected_at'])]
#[ORM\Index(name: 'idx_payment_plan_company_status', columns: ['company_id', 'status'])]
#[ORM\Index(name: 'idx_payment_plan_company_category', columns: ['company_id', 'cashflow_category_id'])]
#[ORM\Index(name: 'idx_payment_plan_company_account', columns: ['company_id', 'money_account_id'])]
#[ORM\Index(name: 'idx_payment_plan_external_sync', columns: ['company_id', 'source', 'external_id'])]
#[ORM\HasLifecycleCallbacks]
class PaymentPlan
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: MoneyAccount::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MoneyAccount $moneyAccount = null;

    #[ORM\ManyToOne(targetEntity: CashflowCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CashflowCategory $cashflowCategory;

    #[ORM\ManyToOne(targetEntity: Counterparty::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Counterparty $counterparty = null;

    # Комментарий: ожидаемая дата оплаты, которую рассчитывает прогноз.
    #[ORM\Column(name: 'expected_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $plannedAt;

    # Комментарий: жесткая дата из документа (счет/договор), не меняется алгоритмами.
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $documentDate;

    # Комментарий: вероятность поступления денег в процентах (0-100).
    #[ORM\Column(type: 'smallint', options: ['default' => 100])]
    private int $probability = 100;

    # Комментарий: источник создания плана (ручной ввод, API, импорт).
    #[ORM\Column(enumType: PaymentPlanSource::class, options: ['default' => PaymentPlanSource::MANUAL->value])]
    private PaymentPlanSource $source = PaymentPlanSource::MANUAL;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isFrozen = false;

    # Комментарий: идентификатор внешнего документа для дедупликации при синхронизации.
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $amount;

    #[ORM\Column(enumType: PaymentPlanStatus::class)]
    private PaymentPlanStatus $status;

    #[ORM\Column(enumType: PaymentPlanType::class)]
    private PaymentPlanType $type;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: PaymentRecurrenceRule::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PaymentRecurrenceRule $recurrenceRule = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $id, Company $company, CashflowCategory $category, \DateTimeImmutable $plannedAt, string $amount)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->cashflowCategory = $category;
        $this->plannedAt = $plannedAt;
        $this->documentDate = $plannedAt;
        $this->amount = $amount;
        $this->status = PaymentPlanStatus::DRAFT;
        $this->type = PaymentPlanType::OUTFLOW;
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

    public function getMoneyAccount(): ?MoneyAccount
    {
        return $this->moneyAccount;
    }

    public function setMoneyAccount(?MoneyAccount $moneyAccount): self
    {
        $this->moneyAccount = $moneyAccount;

        return $this;
    }

    public function getCashflowCategory(): CashflowCategory
    {
        return $this->cashflowCategory;
    }

    public function setCashflowCategory(CashflowCategory $cashflowCategory): self
    {
        $this->cashflowCategory = $cashflowCategory;

        return $this;
    }

    public function getCounterparty(): ?Counterparty
    {
        return $this->counterparty;
    }

    public function setCounterparty(?Counterparty $counterparty): self
    {
        $this->counterparty = $counterparty;

        return $this;
    }

    public function getPlannedAt(): ?\DateTimeImmutable
    {
        return $this->plannedAt;
    }

    public function setPlannedAt(\DateTimeImmutable $plannedAt): self
    {
        $this->plannedAt = $plannedAt;

        return $this;
    }

    public function getExpectedAt(): ?\DateTimeImmutable
    {
        return $this->plannedAt;
    }

    public function setExpectedAt(?\DateTimeImmutable $expectedAt): self
    {
        $this->plannedAt = $expectedAt;

        return $this;
    }

    public function getDocumentDate(): \DateTimeImmutable
    {
        return $this->documentDate;
    }

    public function setDocumentDate(\DateTimeImmutable $documentDate): self
    {
        $this->documentDate = $documentDate;

        return $this;
    }

    public function getProbability(): int
    {
        return $this->probability;
    }

    public function setProbability(int $probability): self
    {
        Assert::range($probability, 0, 100);
        $this->probability = $probability;

        return $this;
    }

    public function getSource(): PaymentPlanSource
    {
        return $this->source;
    }

    public function setSource(PaymentPlanSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function isFrozen(): bool
    {
        return $this->isFrozen;
    }

    public function setIsFrozen(bool $isFrozen): self
    {
        $this->isFrozen = $isFrozen;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getStatus(): PaymentPlanStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentPlanStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getType(): PaymentPlanType
    {
        return $this->type;
    }

    public function setType(PaymentPlanType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function getRecurrenceRule(): ?PaymentRecurrenceRule
    {
        return $this->recurrenceRule;
    }

    public function setRecurrenceRule(?PaymentRecurrenceRule $recurrenceRule): self
    {
        $this->recurrenceRule = $recurrenceRule;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        if (null === $this->createdAt) {
            $this->createdAt = $now;
        }
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
