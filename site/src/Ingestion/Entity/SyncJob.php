<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\Service\SyncJobTransitionPolicy;
use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: SyncJobRepository::class)]
#[ORM\Table(name: 'ingest_sync_jobs')]
#[ORM\Index(name: 'idx_ingest_sync_job_company_status', columns: ['company_id', 'status'])]
#[ORM\Index(name: 'idx_ingest_sync_job_resource_status', columns: ['company_id', 'connection_ref', 'resource_type', 'status'])]
#[ORM\Index(name: 'idx_ingest_sync_job_parent', columns: ['parent_job_id'])]
#[ORM\Index(name: 'idx_ingest_sync_job_kind_status', columns: ['company_id', 'kind', 'status'])]
class SyncJob implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $connectionRef;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $resourceType;

    #[ORM\Column(type: Types::STRING, length: 255, options: ['default' => ''])]
    private string $shopRef;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: SyncJobKind::class)]
    private SyncJobKind $kind;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: SyncJobStatus::class, options: ['default' => 'open'])]
    private SyncJobStatus $status = SyncJobStatus::OPEN;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $windowFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $windowTo;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $parentJobId;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $progressTotal = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $progressDone = 0;

    #[ORM\Column(type: Types::STRING, length: 1024, nullable: true)]
    private ?string $cursorSnapshot = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $connectionRef,
        IngestSource $source,
        string $resourceType,
        SyncJobKind $kind,
        ?\DateTimeImmutable $windowFrom = null,
        ?\DateTimeImmutable $windowTo = null,
        string $shopRef = '',
        ?string $parentJobId = null,
    ) {
        Assert::uuid($companyId);
        Assert::notEmpty($connectionRef);
        Assert::notEmpty($resourceType);

        if (null !== $parentJobId) {
            Assert::uuid($parentJobId);
        }

        if (SyncJobKind::BACKFILL === $kind && (null === $windowFrom || null === $windowTo)) {
            throw new \DomainException('Backfill sync job requires a window.');
        }

        if (null !== $windowFrom && null !== $windowTo && $windowFrom > $windowTo) {
            throw new \DomainException('windowFrom cannot be later than windowTo.');
        }

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->connectionRef = $connectionRef;
        $this->source = $source;
        $this->resourceType = $resourceType;
        $this->kind = $kind;
        $this->windowFrom = $windowFrom;
        $this->windowTo = $windowTo;
        $this->shopRef = $shopRef;
        $this->parentJobId = $parentJobId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function markRunning(): void
    {
        $this->transitionTo(SyncJobStatus::RUNNING);

        ++$this->attempts;
        $this->startedAt ??= new \DateTimeImmutable();
        $this->finishedAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markCompleted(?\DateTimeImmutable $finishedAt = null): void
    {
        $this->transitionTo(SyncJobStatus::COMPLETED);

        $this->finishedAt = $finishedAt ?? new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason, ?\DateTimeImmutable $finishedAt = null): void
    {
        Assert::notEmpty($reason);

        $this->transitionTo(SyncJobStatus::FAILED);

        $this->lastError = $reason;
        $this->finishedAt = $finishedAt ?? new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markCancelled(string $reason): void
    {
        Assert::notEmpty($reason);

        $this->transitionTo(SyncJobStatus::CANCELLED);

        $this->lastError = $reason;
        $this->finishedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function incrementProgress(int $delta = 1): void
    {
        Assert::greaterThanEq($delta, 1);

        $nextProgress = $this->progressDone + $delta;
        if ($this->progressTotal > 0 && $nextProgress > $this->progressTotal) {
            throw new \DomainException('progressDone cannot exceed progressTotal.');
        }

        $this->progressDone = $nextProgress;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setProgressTotal(int $total): void
    {
        Assert::greaterThanEq($total, 1);

        if ($this->progressTotal > 0) {
            throw new \DomainException('progressTotal can be set only once.');
        }

        if ($total < $this->progressDone) {
            throw new \DomainException('progressTotal cannot be lower than progressDone.');
        }

        $this->progressTotal = $total;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setCursorSnapshot(string $value): void
    {
        Assert::notEmpty($value);

        if (SyncJobStatus::OPEN !== $this->status) {
            throw new \DomainException('cursorSnapshot can be set only before the job starts.');
        }

        if (null !== $this->cursorSnapshot) {
            throw new \DomainException('cursorSnapshot can be set only once.');
        }

        $this->cursorSnapshot = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getConnectionRef(): string
    {
        return $this->connectionRef;
    }

    public function getSource(): IngestSource
    {
        return $this->source;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getShopRef(): string
    {
        return $this->shopRef;
    }

    public function getKind(): SyncJobKind
    {
        return $this->kind;
    }

    public function getStatus(): SyncJobStatus
    {
        return $this->status;
    }

    public function getWindowFrom(): ?\DateTimeImmutable
    {
        return $this->windowFrom;
    }

    public function getWindowTo(): ?\DateTimeImmutable
    {
        return $this->windowTo;
    }

    public function getParentJobId(): ?string
    {
        return $this->parentJobId;
    }

    public function getProgressTotal(): int
    {
        return $this->progressTotal;
    }

    public function getProgressDone(): int
    {
        return $this->progressDone;
    }

    public function getCursorSnapshot(): ?string
    {
        return $this->cursorSnapshot;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function transitionTo(SyncJobStatus $next): void
    {
        SyncJobTransitionPolicy::assertCanTransition($this->status, $next);
        $this->status = $next;
    }
}
