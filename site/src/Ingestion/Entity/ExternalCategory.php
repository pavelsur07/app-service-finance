<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\ExternalCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: ExternalCategoryRepository::class)]
#[ORM\Table(name: 'ingest_external_categories')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_ext_category_identity', columns: ['source', 'resource_type', 'scope', 'normalized_key'])]
#[ORM\Index(name: 'idx_ingest_ext_category_status', columns: ['status', 'last_seen_at'])]
#[ORM\Index(name: 'idx_ingest_ext_category_source_resource', columns: ['source', 'resource_type'])]
#[ORM\Index(name: 'idx_ingest_ext_category_external_code', columns: ['source', 'resource_type', 'external_code'])]
class ExternalCategory
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $resourceType;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $scope;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $externalTypeId;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $externalCode;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $externalName;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $providerLabel;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $displayLabel;

    #[ORM\Column(type: Types::STRING, length: 512)]
    private string $normalizedKey;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: ExternalCategoryStatus::class)]
    private ExternalCategoryStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $seenCount = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        IngestSource $source,
        string $resourceType,
        string $scope,
        string $normalizedKey,
        ?string $externalTypeId = null,
        ?string $externalCode = null,
        ?string $externalName = null,
        ?string $providerLabel = null,
        ?string $displayLabel = null,
        ExternalCategoryStatus $status = ExternalCategoryStatus::NEW,
        ?\DateTimeImmutable $seenAt = null,
    ) {
        Assert::notEmpty($resourceType);
        Assert::notEmpty($scope);
        Assert::notEmpty($normalizedKey);

        $now = $seenAt ?? new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->source = $source;
        $this->resourceType = $resourceType;
        $this->scope = $scope;
        $this->normalizedKey = $normalizedKey;
        $this->externalTypeId = $this->normalizeNullable($externalTypeId);
        $this->externalCode = $this->normalizeNullable($externalCode);
        $this->externalName = $this->normalizeNullable($externalName);
        $this->providerLabel = $this->normalizeNullable($providerLabel) ?? $this->externalName;
        $this->displayLabel = $this->normalizeNullable($displayLabel) ?? $this->providerLabel;
        $this->status = $status;
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSource(): IngestSource
    {
        return $this->source;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getExternalTypeId(): ?string
    {
        return $this->externalTypeId;
    }

    public function getExternalCode(): ?string
    {
        return $this->externalCode;
    }

    public function getExternalName(): ?string
    {
        return $this->externalName;
    }

    public function getProviderLabel(): ?string
    {
        return $this->providerLabel;
    }

    public function getDisplayLabel(): ?string
    {
        return $this->displayLabel;
    }

    public function getNormalizedKey(): string
    {
        return $this->normalizedKey;
    }

    public function getStatus(): ExternalCategoryStatus
    {
        return $this->status;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getSeenCount(): int
    {
        return $this->seenCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markSeen(
        ?string $externalTypeId = null,
        ?string $externalName = null,
        ?\DateTimeImmutable $seenAt = null,
        ?string $externalCode = null,
        ?string $providerLabel = null,
        ?string $displayLabel = null,
    ): void {
        $this->externalTypeId ??= $this->normalizeNullable($externalTypeId);
        $this->externalCode ??= $this->normalizeNullable($externalCode);
        $this->externalName ??= $this->normalizeNullable($externalName);
        $this->providerLabel ??= $this->normalizeNullable($providerLabel) ?? $this->externalName;
        $this->displayLabel ??= $this->normalizeNullable($displayLabel) ?? $this->providerLabel;
        $this->lastSeenAt = $seenAt ?? new \DateTimeImmutable();
        ++$this->seenCount;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateDisplayLabel(?string $displayLabel): void
    {
        $this->displayLabel = $this->normalizeNullable($displayLabel);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markMapped(): void
    {
        $this->status = ExternalCategoryStatus::MAPPED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markNew(): void
    {
        $this->status = ExternalCategoryStatus::NEW;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markNeedsIdentification(): void
    {
        $this->status = ExternalCategoryStatus::NEEDS_IDENTIFICATION;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markIgnored(): void
    {
        $this->status = ExternalCategoryStatus::IGNORED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markDeprecated(): void
    {
        $this->status = ExternalCategoryStatus::DEPRECATED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function normalizeNullable(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
