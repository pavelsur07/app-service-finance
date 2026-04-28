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
#[ORM\Index(columns: ['company_id', 'source', 'started_at'], name: 'idx_inventory_sessions_company_source_started')]
#[ORM\Index(columns: ['company_id', 'status', 'started_at'], name: 'idx_inventory_sessions_company_status_started')]
#[ORM\Index(columns: ['status'], name: 'idx_inventory_sessions_active', options: ['where' => "status IN ('pending', 'in_progress')"])]
#[ORM\Index(columns: ['correlation_id'], name: 'idx_inventory_sessions_correlation')]
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

    #[ORM\Column(type: Types::STRING, length: 50, enumType: SnapshotSessionStatus::class, options: ['default' => SnapshotSessionStatus::Pending->value])]
    private SnapshotSessionStatus $status = SnapshotSessionStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $expectedPages;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $receivedPages = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::GUID)]
    private string $correlationId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
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
        if ($this->status !== SnapshotSessionStatus::Pending) {
            throw new \DomainException('Only pending sessions can be moved to in-progress status.');
        }

        $this->status = SnapshotSessionStatus::InProgress;
        $this->touch();
    }

    public function markCompleted(): void
    {
        $this->assertNotTerminal(SnapshotSessionStatus::Completed);

        $this->status = SnapshotSessionStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markPartial(?string $errorMessage = null): void
    {
        $this->assertNotTerminal(SnapshotSessionStatus::Partial);

        $this->status = SnapshotSessionStatus::Partial;
        $this->errorMessage = $errorMessage;
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markFailed(string $errorMessage): void
    {
        Assert::notEmpty(trim($errorMessage));
        $this->assertNotTerminal(SnapshotSessionStatus::Failed);

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

        $this->assertNotTerminalForMutation('expected pages');

        $this->expectedPages = $expectedPages;
        $this->touch();
    }

    public function incrementReceivedPages(int $by = 1): void
    {
        if ($by < 0) {
            throw new \DomainException('increment value must be greater than or equal to 0.');
        }

        $this->assertNotTerminalForMutation('received pages');

        $this->receivedPages += $by;
        $this->touch();
    }

    public function setReceivedPages(int $receivedPages): void
    {
        if ($receivedPages < 0) {
            throw new \DomainException('receivedPages must be greater than or equal to 0.');
        }

        $this->assertNotTerminalForMutation('received pages');

        $this->receivedPages = $receivedPages;
        $this->touch();
    }

    private function isTerminal(): bool
    {
        return in_array($this->status, [
            SnapshotSessionStatus::Completed,
            SnapshotSessionStatus::Partial,
            SnapshotSessionStatus::Failed,
        ], true);
    }

    private function assertNotTerminal(SnapshotSessionStatus $target): void
    {
        if ($this->isTerminal()) {
            throw new \LogicException(sprintf(
                'Cannot transition session %s from terminal status %s to %s',
                $this->id,
                $this->status->value,
                $target->value,
            ));
        }
    }

    private function assertNotTerminalForMutation(string $field): void
    {
        if ($this->isTerminal()) {
            throw new \LogicException(sprintf(
                'Cannot modify %s on terminal session %s (status: %s)',
                $field,
                $this->id,
                $this->status->value,
            ));
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
