<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Exception\StaleTransactionUpdateException;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: FinancialTransactionRepository::class)]
#[ORM\Table(name: 'ingest_financial_transactions')]
#[ORM\UniqueConstraint(name: 'uniq_ftx_natural_key', columns: ['company_id', 'source', 'external_id', 'type'])]
#[ORM\Index(name: 'idx_ftx_company_occurred', columns: ['company_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_ftx_company_shop_occurred', columns: ['company_id', 'shop_ref', 'occurred_at'])]
#[ORM\Index(name: 'idx_ftx_company_group', columns: ['company_id', 'operation_group_id'])]
#[ORM\Index(name: 'idx_ftx_company_type_occurred', columns: ['company_id', 'type', 'occurred_at'])]
#[ORM\Index(name: 'idx_ftx_company_raw', columns: ['company_id', 'raw_record_id'])]
#[ORM\Index(name: 'idx_ftx_company_listing', columns: ['company_id', 'listing_id'])]
class FinancialTransaction implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $connectionRef;

    #[ORM\Column(type: Types::STRING, length: 255, options: ['default' => ''])]
    private string $shopRef;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $externalId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $externalUpdatedAt;

    #[ORM\Column(type: Types::GUID)]
    private string $operationGroupId;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: TransactionType::class)]
    private TransactionType $type;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: TransactionDirection::class)]
    private TransactionDirection $direction;

    #[ORM\Column(type: Types::BIGINT)]
    private string $amountMinor;

    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 64, options: ['default' => 'UTC'])]
    private string $sourceTz = 'UTC';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $orderRef;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $payoutRef;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $counterpartyId;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $listingId;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $listingSku;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $sourceData;

    #[ORM\Column(type: Types::GUID)]
    private string $rawRecordId;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    private ?\DateTimeImmutable $oldOccurredAt = null;

    /**
     * @param array<string, mixed> $sourceData
     */
    public function __construct(
        string $companyId,
        string $connectionRef,
        string $shopRef,
        IngestSource $source,
        string $externalId,
        \DateTimeImmutable $externalUpdatedAt,
        string $operationGroupId,
        TransactionType $type,
        TransactionDirection $direction,
        Money $money,
        \DateTimeImmutable $occurredAt,
        string $rawRecordId,
        ?string $orderRef = null,
        ?string $payoutRef = null,
        ?string $counterpartyId = null,
        ?string $description = null,
        array $sourceData = [],
        string $sourceTz = 'UTC',
        ?string $listingId = null,
        ?string $listingSku = null,
    ) {
        Assert::uuid($companyId);
        Assert::notEmpty($connectionRef);
        Assert::notEmpty($externalId);
        Assert::uuid($operationGroupId);
        Assert::uuid($rawRecordId);
        Assert::regex($money->currency(), '/^[A-Z]{3}$/');

        if (null !== $counterpartyId) {
            Assert::uuid($counterpartyId);
        }

        $this->assertListing($listingId, $listingSku);

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->connectionRef = $connectionRef;
        $this->shopRef = $shopRef;
        $this->source = $source;
        $this->externalId = $externalId;
        $this->externalUpdatedAt = $externalUpdatedAt;
        $this->operationGroupId = $operationGroupId;
        $this->type = $type;
        $this->direction = $direction;
        $this->amountMinor = (string) $money->amountMinor();
        $this->currency = $money->currency();
        $this->occurredAt = $occurredAt;
        $this->rawRecordId = $rawRecordId;
        $this->orderRef = $orderRef;
        $this->payoutRef = $payoutRef;
        $this->counterpartyId = $counterpartyId;
        $this->listingId = $listingId;
        $this->listingSku = $listingSku;
        $this->description = $description;
        $this->sourceData = $sourceData;
        $this->sourceTz = $sourceTz;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    public function replaceFromNewerVersion(
        Money $money,
        TransactionType $type,
        TransactionDirection $direction,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $externalUpdatedAt,
        ?string $orderRef,
        ?string $payoutRef,
        ?string $counterpartyId,
        ?string $description,
        array $sourceData,
        string $rawRecordId,
        ?string $listingId = null,
        ?string $listingSku = null,
    ): void {
        if ($externalUpdatedAt <= $this->externalUpdatedAt) {
            throw new StaleTransactionUpdateException('Incoming transaction version is not newer than existing version.');
        }

        if (null !== $counterpartyId) {
            Assert::uuid($counterpartyId);
        }

        Assert::uuid($rawRecordId);
        $this->assertListing($listingId, $listingSku);

        $this->oldOccurredAt = $this->occurredAt;
        $this->amountMinor = (string) $money->amountMinor();
        $this->currency = $money->currency();
        $this->type = $type;
        $this->direction = $direction;
        $this->occurredAt = $occurredAt;
        $this->externalUpdatedAt = $externalUpdatedAt;
        $this->orderRef = $orderRef;
        $this->payoutRef = $payoutRef;
        $this->counterpartyId = $counterpartyId;
        $this->listingId = $listingId;
        $this->listingSku = $listingSku;
        $this->description = $description;
        $this->sourceData = $sourceData;
        $this->rawRecordId = $rawRecordId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function reattributeRawRecord(string $rawRecordId): bool
    {
        Assert::uuid($rawRecordId);

        if ($this->rawRecordId === $rawRecordId) {
            return false;
        }

        $this->rawRecordId = $rawRecordId;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    /**
     * @param array<string, mixed> $sourceData
     * @param list<string> $keys
     */
    public function replaceSourceDataFields(array $sourceData, array $keys, ?string $description = null): bool
    {
        Assert::allString($keys);
        Assert::allNotEmpty($keys);

        $changed = false;
        $nextSourceData = $this->sourceData;

        foreach ($keys as $key) {
            if (!array_key_exists($key, $sourceData)) {
                continue;
            }

            if (!array_key_exists($key, $nextSourceData) || $nextSourceData[$key] !== $sourceData[$key]) {
                $nextSourceData[$key] = $sourceData[$key];
                $changed = true;
            }
        }

        if (null !== $description && $this->description !== $description) {
            $this->description = $description;
            $changed = true;
        }

        if (!$changed) {
            return false;
        }

        $this->sourceData = $nextSourceData;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    public function oldOccurredAt(): \DateTimeImmutable
    {
        return $this->oldOccurredAt ?? $this->occurredAt;
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

    public function getShopRef(): string
    {
        return $this->shopRef;
    }

    public function getSource(): IngestSource
    {
        return $this->source;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getExternalUpdatedAt(): \DateTimeImmutable
    {
        return $this->externalUpdatedAt;
    }

    public function getOperationGroupId(): string
    {
        return $this->operationGroupId;
    }

    public function getType(): TransactionType
    {
        return $this->type;
    }

    public function getDirection(): TransactionDirection
    {
        return $this->direction;
    }

    public function getAmountMinor(): int
    {
        return (int) $this->amountMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getSourceTz(): string
    {
        return $this->sourceTz;
    }

    public function getOrderRef(): ?string
    {
        return $this->orderRef;
    }

    public function getPayoutRef(): ?string
    {
        return $this->payoutRef;
    }

    public function getCounterpartyId(): ?string
    {
        return $this->counterpartyId;
    }

    public function setListing(string $listingId, string $listingSku): void
    {
        $this->assertListing($listingId, $listingSku);

        $this->listingId = $listingId;
        $this->listingSku = $listingSku;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getListingId(): ?string
    {
        return $this->listingId;
    }

    public function getListingSku(): ?string
    {
        return $this->listingSku;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSourceData(): array
    {
        return $this->sourceData;
    }

    public function getRawRecordId(): string
    {
        return $this->rawRecordId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assertListing(?string $listingId, ?string $listingSku): void
    {
        if (null !== $listingId) {
            Assert::uuid($listingId);
        }

        if (null !== $listingSku) {
            Assert::notEmpty($listingSku);
        }
    }
}
