<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladSyncRunRepository::class)]
#[ORM\Table(name: 'moysklad_sync_runs')]
#[ORM\Index(columns: ['company_id', 'connection_id', 'entity_type', 'started_at'], name: 'idx_moysklad_sync_runs_company_history')]
#[ORM\Index(columns: ['connection_id'], name: 'idx_moysklad_sync_runs_connection')]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_sync_runs_running', columns: ['connection_id', 'entity_type'], options: ['where' => "status = 'running'"])]
class MoySkladSyncRun
{
    public const ERROR_CATEGORIES = ['auth', 'forbidden', 'rate_limited', 'temporary', 'invalid_request', 'invalid_response', 'internal'];

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $connectionId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $entityType;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = 'running';

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $processed = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $created = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $updated = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $unchanged = 0;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $errorCategory = null;

    public function __construct(string $id, string $companyId, string $connectionId, string $entityType, \DateTimeImmutable $startedAt)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::notEmpty(trim($entityType));
        $this->id = strtolower($id);
        $this->companyId = strtolower($companyId);
        $this->connectionId = strtolower($connectionId);
        $this->entityType = $entityType;
        $this->startedAt = $startedAt;
    }

    public function recordPage(int $processed, int $created, int $updated, int $unchanged): void
    {
        if ('running' !== $this->status) {
            throw new \LogicException('Completed run cannot accept pages.');
        }
        if ($processed < 0 || $created < 0 || $updated < 0 || $unchanged < 0 || $processed !== $created + $updated + $unchanged) {
            throw new \InvalidArgumentException('Invalid page counters.');
        }
        $this->processed += $processed;
        $this->created += $created;
        $this->updated += $updated;
        $this->unchanged += $unchanged;
    }

    public function succeed(\DateTimeImmutable $finishedAt): void
    {
        if ('running' !== $this->status) {
            throw new \LogicException('Run has already finished.');
        }
        $this->status = 'succeeded';
        $this->finishedAt = $finishedAt;
    }

    public function fail(string $category, \DateTimeImmutable $finishedAt): void
    {
        if ('running' !== $this->status) {
            throw new \LogicException('Run has already finished.');
        }
        if (!in_array($category, self::ERROR_CATEGORIES, true)) {
            throw new \InvalidArgumentException('Invalid sync error category.');
        }
        $this->status = 'failed';
        $this->errorCategory = $category;
        $this->finishedAt = $finishedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getUnchanged(): int
    {
        return $this->unchanged;
    }

    public function getErrorCategory(): ?string
    {
        return $this->errorCategory;
    }
}
