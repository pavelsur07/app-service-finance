<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: ExternalCategoryMappingRepository::class)]
#[ORM\Table(name: 'ingest_external_category_mappings')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_ext_category_mapping_category', columns: ['external_category_id'])]
#[ORM\Index(name: 'idx_ingest_ext_category_mapping_status', columns: ['status', 'updated_at'])]
class ExternalCategoryMapping
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\OneToOne(targetEntity: ExternalCategory::class)]
    #[ORM\JoinColumn(name: 'external_category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExternalCategory $externalCategory;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $canonicalCode;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $canonicalLabel;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $canonicalGroup;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: TransactionType::class)]
    private TransactionType $transactionType;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: TransactionDirection::class, nullable: true)]
    private ?TransactionDirection $defaultDirection;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 9000])]
    private int $sortOrder;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $known;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: ExternalCategoryMappingStatus::class)]
    private ExternalCategoryMappingStatus $status;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $updatedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        ExternalCategory $externalCategory,
        string $canonicalCode,
        string $canonicalLabel,
        string $canonicalGroup,
        TransactionType $transactionType,
        int $sortOrder,
        ?TransactionDirection $defaultDirection = null,
        bool $known = true,
        ExternalCategoryMappingStatus $status = ExternalCategoryMappingStatus::ACTIVE,
        ?string $updatedBy = null,
    ) {
        Assert::notEmpty($canonicalCode);
        Assert::notEmpty($canonicalLabel);
        Assert::notEmpty($canonicalGroup);
        Assert::greaterThan($sortOrder, 0);
        if (null !== $updatedBy) {
            Assert::uuid($updatedBy);
        }

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->externalCategory = $externalCategory;
        $this->canonicalCode = $canonicalCode;
        $this->canonicalLabel = $canonicalLabel;
        $this->canonicalGroup = $canonicalGroup;
        $this->transactionType = $transactionType;
        $this->sortOrder = $sortOrder;
        $this->defaultDirection = $defaultDirection;
        $this->known = $known;
        $this->status = $status;
        $this->updatedBy = $updatedBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;

        if (ExternalCategoryMappingStatus::ACTIVE === $status) {
            $externalCategory->markMapped();
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getExternalCategory(): ExternalCategory
    {
        return $this->externalCategory;
    }

    public function getCanonicalCode(): string
    {
        return $this->canonicalCode;
    }

    public function getCanonicalLabel(): string
    {
        return $this->canonicalLabel;
    }

    public function getCanonicalGroup(): string
    {
        return $this->canonicalGroup;
    }

    public function getTransactionType(): TransactionType
    {
        return $this->transactionType;
    }

    public function getDefaultDirection(): ?TransactionDirection
    {
        return $this->defaultDirection;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isKnown(): bool
    {
        return $this->known;
    }

    public function getStatus(): ExternalCategoryMappingStatus
    {
        return $this->status;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(
        string $canonicalCode,
        string $canonicalLabel,
        string $canonicalGroup,
        TransactionType $transactionType,
        int $sortOrder,
        ?TransactionDirection $defaultDirection,
        bool $known,
        ExternalCategoryMappingStatus $status,
        ?string $updatedBy = null,
    ): void {
        Assert::notEmpty($canonicalCode);
        Assert::notEmpty($canonicalLabel);
        Assert::notEmpty($canonicalGroup);
        Assert::greaterThan($sortOrder, 0);
        if (null !== $updatedBy) {
            Assert::uuid($updatedBy);
        }

        $this->canonicalCode = $canonicalCode;
        $this->canonicalLabel = $canonicalLabel;
        $this->canonicalGroup = $canonicalGroup;
        $this->transactionType = $transactionType;
        $this->sortOrder = $sortOrder;
        $this->defaultDirection = $defaultDirection;
        $this->known = $known;
        $this->status = $status;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable();

        if (ExternalCategoryMappingStatus::ACTIVE === $status) {
            $this->externalCategory->markMapped();
        }
    }
}
