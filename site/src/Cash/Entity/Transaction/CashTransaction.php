<?php

namespace App\Cash\Entity\Transaction;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashTransactionRepository::class)]
#[ORM\Table(name: 'cash_transaction')]
#[ORM\Index(name: 'idx_company_account_occurred', columns: ['company_id', 'money_account_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_company_occurred', columns: ['company_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_cash_transaction_company_is_transfer', columns: ['company_id', 'is_transfer'])]
#[ORM\UniqueConstraint(name: 'uniq_cashflow_import', columns: ['company_id', 'import_source', 'external_id'])]
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

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $responsibilityCenterId = null;

    #[ORM\OneToMany(mappedBy: 'cashTransaction', targetEntity: Document::class)]
    private Collection $documents;

    /** @var Collection<int, CashTransactionSplit> */
    #[ORM\OneToMany(mappedBy: 'cashTransaction', targetEntity: CashTransactionSplit::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $splits;

    #[ORM\Column(enumType: CashDirection::class)]
    private CashDirection $direction;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $vatRatePercent = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2, nullable: true)]
    private ?string $vatAmount = null;

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

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $importSource = null;

    #[ORM\Column(name: 'dedupe_hash', length: 64, nullable: true)]
    private ?string $dedupeHash = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isTransfer = false;

    #[ORM\Column(type: 'json')]
    private array $rawData = [];

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $allocatedAmount = '0';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deletedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deleteReason = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $hasViolatedDocument = false;

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
        $this->documents = new ArrayCollection();
        $this->splits = new ArrayCollection();
    }

    /**
     * Наружу отдаётся снимок, а не живая коллекция: иначе инварианты набора
     * обходятся вызовом getSplits()->add() мимо replaceSplits().
     *
     * @return Collection<int, CashTransactionSplit>
     */
    public function getSplits(): Collection
    {
        return new ArrayCollection($this->splits->toArray());
    }

    /**
     * Страховка от мутации дочерней строки в обход replaceSplits().
     *
     * Вызывается из PrePersist и PreUpdate самой строки — только для строк, которые
     * действительно пишутся, поэтому в массовых сценариях коллекция владельца уже
     * загружена и лишних запросов нет. Неинициализированную коллекцию приходится
     * загрузить: строку могли достать через репозиторий после EntityManager::clear(),
     * и ранний выход по «не загружено» как раз и открывал обход.
     */
    public function assertSplitsBalanced(): void
    {
        if ($this->splits->isEmpty()) {
            return;
        }

        if (0 !== bccomp($this->getSplitsTotal(), $this->amount, 2)) {
            throw new \DomainException(sprintf('Сумма строк разбивки (%s) не равна сумме транзакции (%s). Состав меняют только через replaceSplits().', $this->getSplitsTotal(), $this->amount));
        }
    }

    public function getSplitsTotal(): string
    {
        $total = '0';
        foreach ($this->splits as $split) {
            $total = bcadd($total, $split->getAmount(), 2);
        }

        return $total;
    }

    /**
     * Заменяет состав строк разбивки целиком.
     *
     * Единственная точка, где проверяются инварианты набора: сумма строк равна сумме
     * транзакции, категории не повторяются, а мультиразбивка не заходит в категории,
     * из которых создаются документы ОПиУ (см. решение D1 в плане задачи).
     *
     * @param list<CashTransactionSplit> $splits
     */
    public function replaceSplits(array $splits): self
    {
        Assert::allIsInstanceOf($splits, CashTransactionSplit::class);

        if ([] === $splits) {
            throw new \DomainException('Разбивка транзакции ДДС не может быть пустой.');
        }

        $categoryIds = [];
        foreach ($splits as $split) {
            if ($split->getCashTransaction() !== $this) {
                throw new \DomainException('Строка разбивки принадлежит другой транзакции.');
            }

            $categoryId = (string) $split->getCashflowCategory()->getId();
            if (isset($categoryIds[$categoryId])) {
                throw new \DomainException('Категория ДДС повторяется в разбивке.');
            }
            $categoryIds[$categoryId] = true;
        }

        $total = '0';
        foreach ($splits as $split) {
            $total = bcadd($total, $split->getAmount(), 2);
        }

        if (0 !== bccomp($total, $this->amount, 2)) {
            throw new \DomainException(sprintf('Сумма строк разбивки (%s) не равна сумме транзакции (%s).', $total, $this->amount));
        }

        if (count($splits) > 1) {
            foreach ($splits as $split) {
                if ($split->getCashflowCategory()->isAllowPlDocument()) {
                    throw new \DomainException(sprintf('Категория «%s» участвует в документах ОПиУ, разбивать транзакцию по ней нельзя.', $split->getCashflowCategory()->getName()));
                }
            }
        }

        // Строки с той же категорией переиспользуются: пара DELETE+INSERT по одному
        // ключу (transaction, category) в одном flush падает на уникальном индексе,
        // потому что Doctrine выполняет вставку раньше удаления.
        $existingByCategory = [];
        foreach ($this->splits as $existing) {
            $existingByCategory[(string) $existing->getCashflowCategory()->getId()] = $existing;
        }

        $result = [];
        foreach ($splits as $split) {
            $categoryId = (string) $split->getCashflowCategory()->getId();
            $existing = $existingByCategory[$categoryId] ?? null;

            // source сохраняется, пока категория та же: происхождение описывает именно
            // категоризацию, а не сумму. Иначе правка суммы человеком помечала бы
            // авто-категорию как ручную, а правило, изменившее только ЦФО, — наоборот.
            $result[] = null !== $existing
                ? $existing->changeAmount($split->getAmount())
                : $split;
        }

        $this->splits->clear();
        foreach ($result as $split) {
            $this->splits->add($split);
        }

        return $this;
    }

    /**
     * Убирает все строки разбивки. Допустимо только когда категория не задана:
     * строки зеркалят колонку, включая её пустое состояние.
     */
    public function clearSplits(): self
    {
        if (null !== $this->cashflowCategory) {
            throw new \DomainException('Нельзя убрать разбивку у транзакции с заданной категорией ДДС.');
        }

        $this->splits->clear();

        return $this;
    }

    /**
     * Состав строк для aggregate-аудита, отсортированный, чтобы диff не шумел
     * на изменении порядка.
     *
     * @return list<array{category: string, categoryName: string, amount: string, source: string}>
     */
    public function splitsAuditSnapshot(): array
    {
        $rows = [];
        foreach ($this->splits as $split) {
            $rows[] = $split->toAuditRow();
        }

        usort($rows, static fn (array $a, array $b): int => $a['category'] <=> $b['category']);

        return $rows;
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

    public function getResponsibilityCenterId(): ?string
    {
        return $this->responsibilityCenterId;
    }

    public function setResponsibilityCenterId(?string $responsibilityCenterId): self
    {
        $this->responsibilityCenterId = $responsibilityCenterId;

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

    public function getVatRatePercent(): ?int
    {
        return $this->vatRatePercent;
    }

    public function setVatRatePercent(?int $vatRatePercent): self
    {
        $this->vatRatePercent = $vatRatePercent;

        return $this;
    }

    public function getVatAmount(): ?string
    {
        return $this->vatAmount;
    }

    public function setVatAmount(?string $vatAmount): self
    {
        $this->vatAmount = $vatAmount;

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

    public function getDedupeHash(): ?string
    {
        return $this->dedupeHash;
    }

    public function setDedupeHash(?string $hash): void
    {
        $this->dedupeHash = $hash;
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

    public function getImportSource(): ?string
    {
        return $this->importSource;
    }

    public function setImportSource(?string $importSource): self
    {
        $this->importSource = $importSource;

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

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): self
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setCashTransaction($this);
        }

        $this->allocatedAmount = number_format(
            $this->getAllocatedAmount() + $document->getTotalAmount(),
            2,
            '.',
            '',
        );

        return $this;
    }

    public function recalculateAllocatedAmount(): self
    {
        $allocated = 0.0;

        foreach ($this->documents as $document) {
            if ($document instanceof Document) {
                $allocated += $document->getTotalAmount();
            }
        }

        $this->allocatedAmount = number_format($allocated, 2, '.', '');

        return $this;
    }

    public function getAllocatedAmount(): float
    {
        return (float) $this->allocatedAmount;
    }

    public function getRemainingAmount(?Document $excludingDocument = null): float
    {
        return $this->calculateRemainingAmount($excludingDocument);
    }

    public function canAllocateAmount(float $amount, ?Document $excludingDocument = null): bool
    {
        if ($amount <= 0.0) {
            return false;
        }

        return $amount <= $this->getRemainingAmount($excludingDocument);
    }

    public function assertCanAllocateAmount(float $amount, ?Document $excludingDocument = null): void
    {
        if ($amount <= 0.0) {
            throw new \DomainException('Сумма документа должна быть больше нуля.');
        }

        if (!$this->canAllocateAmount($amount, $excludingDocument)) {
            throw new \DomainException('Сумма документа превышает доступный остаток транзакции ДДС.');
        }
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

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function markDeleted(?string $userId, ?string $reason = null): void
    {
        if (null !== $this->deletedAt) {
            return;
        }

        $this->deletedAt = new \DateTimeImmutable();
        $this->deletedBy = $userId;
        $this->deleteReason = $reason;
    }

    public function restore(): void
    {
        $this->deletedAt = null;
        $this->deletedBy = null;
        $this->deleteReason = null;
    }

    public function isHasViolatedDocument(): bool
    {
        return $this->hasViolatedDocument;
    }

    public function markAsHavingViolatedDocument(): void
    {
        $this->hasViolatedDocument = true;
    }

    private function calculateRemainingAmount(?Document $excludingDocument): float
    {
        if (null === $this->amount) {
            return 0.0;
        }

        $this->recalculateAllocatedAmount();

        $allocated = (float) $this->allocatedAmount;

        if ($excludingDocument instanceof Document && $this->documents->contains($excludingDocument)) {
            $allocated -= $excludingDocument->getTotalAmount();
        }

        $remaining = (float) $this->amount - $allocated;

        return max($remaining, 0.0);
    }
}
