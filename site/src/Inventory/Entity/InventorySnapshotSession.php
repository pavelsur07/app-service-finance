<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Inventory\Enum\SnapshotSessionStatus;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: InventorySnapshotSessionRepository::class)]
#[ORM\Table(name: 'inventory_snapshot_sessions')]
#[ORM\Index(columns: ['company_id', 'source', 'status'], name: 'idx_inv_snapshot_sessions_company_source_status')]
#[ORM\Index(columns: ['correlation_id'], name: 'idx_inv_snapshot_sessions_correlation_id')]
class InventorySnapshotSession
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: MarketplaceType::class)]
    private MarketplaceType $source;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: SnapshotTriggerType::class)]
    private SnapshotTriggerType $triggerType;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $triggeredBy;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: SnapshotSessionStatus::class)]
    private SnapshotSessionStatus $status = SnapshotSessionStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $expectedPages;

    #[ORM\Column(type: Types::INTEGER)]
    private int $receivedPages = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::GUID)]
    private string $correlationId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        MarketplaceType $source,
        SnapshotTriggerType $triggerType,
        ?string $correlationId = null,
        ?string $triggeredBy = null,
        ?int $expectedPages = null,
    ) {
        Assert::uuid($companyId);

        if ($triggeredBy !== null) {
            Assert::uuid($triggeredBy);
        }

        if ($correlationId !== null) {
            Assert::uuid($correlationId);
        }

        if ($expectedPages !== null && $expectedPages < 0) {
            throw new \DomainException('expectedPages must be greater than or equal to 0.');
        }

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->source = $source;
        $this->triggerType = $triggerType;
        $this->triggeredBy = $triggeredBy;
        $this->status = SnapshotSessionStatus::Pending;
        $this->startedAt = $now;
        $this->expectedPages = $expectedPages;
        $this->correlationId = $correlationId ?? Uuid::uuid7()->toString();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getSource(): MarketplaceType
    {
        return $this->source;
    }

    public function getTriggerType(): SnapshotTriggerType
    {
        return $this->triggerType;
    }

    public function getTriggeredBy(): ?string
    {
        return $this->triggeredBy;
    }

    public function getStatus(): SnapshotSessionStatus
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getExpectedPages(): ?int
    {
        return $this->expectedPages;
    }

    public function getReceivedPages(): int
    {
        return $this->receivedPages;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markInProgress(): void
    {
        $this->status = SnapshotSessionStatus::InProgress;
        $this->touch();
    }

    public function markCompleted(): void
    {
        $this->status = SnapshotSessionStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markPartial(?string $errorMessage = null): void
    {
        $this->status = SnapshotSessionStatus::Partial;
        $this->errorMessage = $errorMessage;
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markFailed(string $errorMessage): void
    {
        Assert::notEmpty(trim($errorMessage));

        $this->status = SnapshotSessionStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function setExpectedPages(?int $expectedPages): void
    {
        if ($expectedPages !== null && $expectedPages < 0) {
            throw new \DomainException('expectedPages must be greater than or equal to 0.');
        }

        $this->expectedPages = $expectedPages;
        $this->touch();
    }

    public function incrementReceivedPages(int $by = 1): void
    {
        if ($by < 0) {
            throw new \DomainException('increment value must be greater than or equal to 0.');
        }

        $this->receivedPages += $by;
        $this->touch();
    }

    public function setReceivedPages(int $receivedPages): void
    {
        if ($receivedPages < 0) {
            throw new \DomainException('receivedPages must be greater than or equal to 0.');
        }

        $this->receivedPages = $receivedPages;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
