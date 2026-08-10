<?php

declare(strict_types=1);

namespace App\Cash\Entity\Transfer;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Cash\ValueObject\Transfer\EffectiveExchangeRate;
use App\Company\Entity\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashTransferRepository::class)]
#[ORM\Table(name: 'cash_transfer')]
#[ORM\UniqueConstraint(name: 'uniq_cash_transfer_company_idempotency', columns: ['company_id', 'idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_cash_transfer_source_transaction', columns: ['source_transaction_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cash_transfer_target_transaction', columns: ['target_transaction_id'])]
#[ORM\Index(name: 'idx_cash_transfer_company_created', columns: ['company_id', 'created_at'])]
#[ORM\Index(name: 'idx_cash_transfer_company_deleted', columns: ['company_id', 'deleted_at'])]
#[ORM\HasLifecycleCallbacks]
class CashTransfer
{
    public const RATE_SOURCE_MANUAL_EFFECTIVE = EffectiveExchangeRate::SOURCE_MANUAL_EFFECTIVE;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\OneToOne(targetEntity: CashTransaction::class)]
    #[ORM\JoinColumn(name: 'source_transaction_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CashTransaction $sourceTransaction;

    #[ORM\OneToOne(targetEntity: CashTransaction::class)]
    #[ORM\JoinColumn(name: 'target_transaction_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CashTransaction $targetTransaction;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(type: Types::DECIMAL, precision: 38, scale: 18, nullable: true)]
    private ?string $effectiveRate;

    #[ORM\Column(type: Types::STRING, length: 3, nullable: true)]
    private ?string $rateBaseCurrency;

    #[ORM\Column(type: Types::STRING, length: 3, nullable: true)]
    private ?string $rateQuoteCurrency;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rateDate;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $rateSource;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $deletedBy = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $deleteReason = null;

    public function __construct(
        string $id,
        Company $company,
        CashTransaction $sourceTransaction,
        CashTransaction $targetTransaction,
        string $idempotencyKey,
        ?string $effectiveRate = null,
        ?string $rateBaseCurrency = null,
        ?string $rateQuoteCurrency = null,
        ?\DateTimeImmutable $rateDate = null,
        ?string $rateSource = null,
    ) {
        Assert::uuid($id);
        Assert::notWhitespaceOnly($idempotencyKey);
        Assert::maxLength($idempotencyKey, 128);

        $this->assertLegs($company, $sourceTransaction, $targetTransaction);
        [$effectiveRate, $rateBaseCurrency, $rateQuoteCurrency] = $this->normalizeRateMetadata(
            $sourceTransaction,
            $targetTransaction,
            $effectiveRate,
            $rateBaseCurrency,
            $rateQuoteCurrency,
            $rateDate,
            $rateSource,
        );

        $now = new \DateTimeImmutable();
        $this->id = $id;
        $this->company = $company;
        $this->sourceTransaction = $sourceTransaction;
        $this->targetTransaction = $targetTransaction;
        $this->idempotencyKey = trim($idempotencyKey);
        $this->effectiveRate = $effectiveRate;
        $this->rateBaseCurrency = $rateBaseCurrency;
        $this->rateQuoteCurrency = $rateQuoteCurrency;
        $this->rateDate = $rateDate;
        $this->rateSource = $rateSource;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getSourceTransaction(): CashTransaction
    {
        return $this->sourceTransaction;
    }

    public function getTargetTransaction(): CashTransaction
    {
        return $this->targetTransaction;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getEffectiveRate(): ?string
    {
        return $this->effectiveRate;
    }

    public function getRateBaseCurrency(): ?string
    {
        return $this->rateBaseCurrency;
    }

    public function getRateQuoteCurrency(): ?string
    {
        return $this->rateQuoteCurrency;
    }

    public function getRateDate(): ?\DateTimeImmutable
    {
        return $this->rateDate;
    }

    public function getRateSource(): ?string
    {
        return $this->rateSource;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getDeletedBy(): ?string
    {
        return $this->deletedBy;
    }

    public function getDeleteReason(): ?string
    {
        return $this->deleteReason;
    }

    public function markDeleted(?string $userId, ?string $reason = null): void
    {
        if ($this->isDeleted()) {
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

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function assertLegs(
        Company $company,
        CashTransaction $sourceTransaction,
        CashTransaction $targetTransaction,
    ): void {
        if ($sourceTransaction === $targetTransaction || $sourceTransaction->getId() === $targetTransaction->getId()) {
            throw new \DomainException('Ноги перевода должны быть разными транзакциями.');
        }

        $companyId = $company->getId();
        if ($sourceTransaction->getCompany()->getId() !== $companyId || $targetTransaction->getCompany()->getId() !== $companyId) {
            throw new \DomainException('Обе ноги перевода должны принадлежать компании перевода.');
        }

        if (CashDirection::OUTFLOW !== $sourceTransaction->getDirection()
            || CashDirection::INFLOW !== $targetTransaction->getDirection()) {
            throw new \DomainException('Перевод должен состоять из исходящей и входящей транзакций.');
        }

        if ($sourceTransaction->getMoneyAccount()->getId() === $targetTransaction->getMoneyAccount()->getId()) {
            throw new \DomainException('Счета перевода должны различаться.');
        }

        if (!$sourceTransaction->isTransfer() || !$targetTransaction->isTransfer()) {
            throw new \DomainException('Обе ноги агрегата должны быть помечены как перевод.');
        }

        if ($sourceTransaction->getOccurredAt() != $targetTransaction->getOccurredAt()) {
            throw new \DomainException('Обе ноги перевода должны иметь одну дату.');
        }

        if (bccomp($sourceTransaction->getAmount(), '0', 2) <= 0 || bccomp($targetTransaction->getAmount(), '0', 2) <= 0) {
            throw new \DomainException('Суммы перевода должны быть положительными.');
        }
    }

    /**
     * @return array{?string, ?string, ?string}
     */
    private function normalizeRateMetadata(
        CashTransaction $sourceTransaction,
        CashTransaction $targetTransaction,
        ?string $effectiveRate,
        ?string $rateBaseCurrency,
        ?string $rateQuoteCurrency,
        ?\DateTimeImmutable $rateDate,
        ?string $rateSource,
    ): array {
        $sourceCurrency = FiatCurrency::fromCode($sourceTransaction->getCurrency());
        $targetCurrency = FiatCurrency::fromCode($targetTransaction->getCurrency());

        if ($sourceCurrency === $targetCurrency) {
            if (0 !== bccomp($sourceTransaction->getAmount(), $targetTransaction->getAmount(), 2)) {
                throw new \DomainException('Суммы перевода в одной валюте должны совпадать.');
            }

            if (null !== $effectiveRate || null !== $rateBaseCurrency || null !== $rateQuoteCurrency || null !== $rateDate || null !== $rateSource) {
                throw new \DomainException('Для перевода в одной валюте FX-метаданные не задаются.');
            }

            return [null, null, null];
        }

        if (!$sourceCurrency->canTransferTo($targetCurrency)) {
            throw new \DomainException('Разрешены только кросс-валютные переводы RUB↔USD и RUB↔EUR.');
        }

        if (null === $effectiveRate || null === $rateBaseCurrency || null === $rateQuoteCurrency || null === $rateDate || null === $rateSource) {
            throw new \DomainException('Для кросс-валютного перевода обязательны полные FX-метаданные.');
        }

        $effectiveRate = trim($effectiveRate);
        if (1 !== preg_match('/^\d+(?:\.\d{1,18})?$/', $effectiveRate) || bccomp($effectiveRate, '0', 18) <= 0) {
            throw new \DomainException('Эффективный курс должен быть положительным decimal с точностью до 18 знаков.');
        }

        $rateBaseCurrency = FiatCurrency::fromCode($rateBaseCurrency)->value;
        $rateQuoteCurrency = FiatCurrency::fromCode($rateQuoteCurrency)->value;
        if ($rateBaseCurrency !== $sourceCurrency->value || $rateQuoteCurrency !== $targetCurrency->value) {
            throw new \DomainException('Направление котировки должно совпадать с направлением перевода.');
        }

        if (self::RATE_SOURCE_MANUAL_EFFECTIVE !== $rateSource) {
            throw new \DomainException('Источник курса перевода должен быть manual_effective.');
        }

        if ($rateDate != $sourceTransaction->getOccurredAt()) {
            throw new \DomainException('Дата курса должна совпадать с датой перевода.');
        }

        return [$effectiveRate, $rateBaseCurrency, $rateQuoteCurrency];
    }
}
