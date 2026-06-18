<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Repository\IngestCursorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: IngestCursorRepository::class)]
#[ORM\Table(name: 'ingest_cursors')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_cursor_key', columns: ['company_id', 'connection_ref', 'resource_type', 'shop_ref'])]
#[ORM\Index(name: 'idx_ingest_cursor_company_connection', columns: ['company_id', 'connection_ref'])]
class IngestCursor implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $connectionRef;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $resourceType;

    #[ORM\Column(type: Types::STRING, length: 255, options: ['default' => ''])]
    private string $shopRef;

    #[ORM\Column(type: Types::STRING, length: 1024, options: ['default' => ''])]
    private string $cursorValue = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $lastSyncJobId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $connectionRef,
        string $resourceType,
        string $shopRef = '',
    ) {
        Assert::uuid($companyId);
        Assert::notEmpty($connectionRef);
        Assert::notEmpty($resourceType);

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->connectionRef = $connectionRef;
        $this->resourceType = $resourceType;
        $this->shopRef = $shopRef;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function advance(
        string $newCursorValue,
        string $syncJobId,
        ?\DateTimeImmutable $fetchedAt = null,
    ): void {
        Assert::notEmpty($newCursorValue);
        Assert::uuid($syncJobId);

        $now = new \DateTimeImmutable();

        $this->cursorValue = $newCursorValue;
        $this->lastSyncJobId = $syncJobId;
        $this->lastFetchedAt = $fetchedAt ?? $now;
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

    public function getConnectionRef(): string
    {
        return $this->connectionRef;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getShopRef(): string
    {
        return $this->shopRef;
    }

    public function getCursorValue(): string
    {
        return $this->cursorValue;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function getLastSyncJobId(): ?string
    {
        return $this->lastSyncJobId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
