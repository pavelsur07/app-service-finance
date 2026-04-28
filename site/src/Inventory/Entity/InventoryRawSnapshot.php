<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Inventory\Repository\InventoryRawSnapshotRepository;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: InventoryRawSnapshotRepository::class)]
#[ORM\Table(name: 'inventory_raw_snapshots')]
#[ORM\Index(columns: ['company_id', 'source', 'fetched_at'], name: 'idx_inventory_raw_company_source_fetched')]
#[ORM\Index(columns: ['company_id', 'snapshot_session_id'], name: 'idx_inventory_raw_company_session')]
#[ORM\Index(columns: ['snapshot_session_id', 'page_number'], name: 'idx_inventory_raw_session_page')]
#[ORM\Index(columns: ['is_processed'], name: 'idx_inventory_raw_unprocessed', options: ['where' => 'is_processed = false'])]
#[ORM\Index(columns: ['correlation_id'], name: 'idx_inventory_raw_correlation')]
class InventoryRawSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $snapshotSessionId;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: MarketplaceType::class)]
    private MarketplaceType $source;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $sourceEndpoint;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $requestParams;

    #[ORM\Column(type: Types::INTEGER)]
    private int $responseStatus;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $responseBody;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $pageNumber;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(type: Types::INTEGER)]
    private int $fetchDurationMs;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isProcessed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $processingError = null;

    #[ORM\Column(type: Types::GUID)]
    private string $correlationId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $requestParams
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $companyId,
        string $snapshotSessionId,
        MarketplaceType $source,
        string $sourceEndpoint,
        array $requestParams,
        int $responseStatus,
        array $responseBody,
        \DateTimeImmutable $fetchedAt,
        int $fetchDurationMs,
        string $correlationId,
        ?int $pageNumber = null,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($snapshotSessionId);
        Assert::uuid($correlationId);

        Assert::notEmpty(trim($sourceEndpoint), 'sourceEndpoint must not be empty.');
        Assert::greaterThan($responseStatus, 0, 'responseStatus must be greater than 0.');
        Assert::greaterThanEq($fetchDurationMs, 0, 'fetchDurationMs must be greater than or equal to 0.');
        Assert::nullOrGreaterThanEq($pageNumber, 1, 'pageNumber must be null or greater than or equal to 1.');

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->snapshotSessionId = $snapshotSessionId;
        $this->source = $source;
        $this->sourceEndpoint = $sourceEndpoint;
        $this->requestParams = $requestParams;
        $this->responseStatus = $responseStatus;
        $this->responseBody = $responseBody;
        $this->fetchedAt = $fetchedAt;
        $this->fetchDurationMs = $fetchDurationMs;
        $this->correlationId = $correlationId;
        $this->pageNumber = $pageNumber;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getSnapshotSessionId(): string
    {
        return $this->snapshotSessionId;
    }

    public function getSource(): MarketplaceType
    {
        return $this->source;
    }

    public function getSourceEndpoint(): string
    {
        return $this->sourceEndpoint;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestParams(): array
    {
        return $this->requestParams;
    }

    public function getResponseStatus(): int
    {
        return $this->responseStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    public function getPageNumber(): ?int
    {
        return $this->pageNumber;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getFetchDurationMs(): int
    {
        return $this->fetchDurationMs;
    }

    public function isProcessed(): bool
    {
        return $this->isProcessed;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function markAsProcessed(): void
    {
        $this->isProcessed = true;
        $this->processedAt = new \DateTimeImmutable();
        $this->processingError = null;
    }

    public function markAsFailed(string $processingError): void
    {
        Assert::notEmpty(trim($processingError));

        $this->isProcessed = false;
        $this->processedAt = new \DateTimeImmutable();
        $this->processingError = $processingError;
    }
}
