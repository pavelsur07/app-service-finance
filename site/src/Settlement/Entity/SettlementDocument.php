<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementDocumentStatus;
use App\Settlement\Enum\SettlementSourceType;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'settlement_documents')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_documents_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_document_request', columns: ['company_id', 'request_id'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_event_sequence', columns: ['company_id', 'event_sequence'])]
class SettlementDocument
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** @param array<string, bool|int|string|null> $sourceSnapshot */
    public function __construct(
        #[ORM\Column(type: Types::GUID)]
        private string $companyId,
        #[ORM\Column(type: Types::GUID)]
        private string $requestId,
        #[ORM\Column(enumType: SettlementDocumentKind::class)]
        private SettlementDocumentKind $kind,
        #[ORM\Column(type: Types::GUID)]
        private string $counterpartyId,
        #[ORM\Column(enumType: SettlementBucket::class)]
        private SettlementBucket $bucket,
        #[ORM\Embedded(class: Money::class, columnPrefix: 'amount_')]
        private Money $amount,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $effectiveDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $dueDate,
        #[ORM\Column(type: Types::TEXT)]
        private string $reason,
        #[ORM\Column(type: Types::GUID)]
        private string $actorUserId,
        #[ORM\Column(enumType: SettlementSourceType::class)]
        private SettlementSourceType $sourceType = SettlementSourceType::MANUAL,
        #[ORM\Column(type: Types::GUID, nullable: true)]
        private ?string $sourceId = null,
        #[ORM\Column(type: Types::JSON)]
        private array $sourceSnapshot = [],
        #[ORM\Column(type: Types::GUID, nullable: true)]
        private ?string $reversedDocumentId = null,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
        Assert::uuid($requestId);
        Assert::uuid($counterpartyId);
        Assert::uuid($actorUserId);
        Assert::true($amount->isPositive());
        Assert::same($amount->currency(), 'RUB');
        Assert::notWhitespaceOnly($reason);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getKind(): SettlementDocumentKind
    {
        return $this->kind;
    }

    public function getCounterpartyId(): string
    {
        return $this->counterpartyId;
    }

    public function getBucket(): SettlementBucket
    {
        return $this->bucket;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getActorUserId(): string
    {
        return $this->actorUserId;
    }

    public function getSourceType(): SettlementSourceType
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    /** @return array<string, bool|int|string|null> */
    public function getSourceSnapshot(): array
    {
        return $this->sourceSnapshot;
    }

    public function getReversedDocumentId(): ?string
    {
        return $this->reversedDocumentId;
    }

    #[ORM\Column(enumType: SettlementDocumentStatus::class)]
    private SettlementDocumentStatus $status = SettlementDocumentStatus::DRAFT;
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function getStatus(): SettlementDocumentStatus
    {
        return $this->status;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    #[ORM\Column(type: \App\Shared\Infrastructure\Doctrine\MoneyAmountType::NAME, nullable: true)]
    private ?int $eventSequence = null;

    public function getEventSequence(): ?int
    {
        return $this->eventSequence;
    }

    public function post(int $expectedVersion, int $eventSequence): void
    {
        if (SettlementDocumentStatus::DRAFT !== $this->status || $expectedVersion !== $this->version) {
            throw new \DomainException('Документ уже проведён или изменён.');
        }
        Assert::positiveInteger($eventSequence);
        $this->eventSequence = $eventSequence;
        $this->status = SettlementDocumentStatus::POSTED;
        ++$this->version;
    }
}
