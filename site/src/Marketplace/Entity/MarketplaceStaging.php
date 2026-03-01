<?php

namespace App\Marketplace\Entity;

use App\Company\Entity\Company;
use App\Marketplace\Enum\ProcessingStatus;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Repository\MarketplaceStagingRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * MarketplaceStaging - промежуточная таблица для отслеживания каждой записи
 *
 * Workflow:
 * 1. Raw Document → Staging (парсинг) - status: pending
 * 2. Staging → MarketplaceSale/Return/Cost (обработка) - status: completed/failed
 * 3. Reprocessing: WHERE status='failed'
 */
#[ORM\Entity(repositoryClass: MarketplaceStagingRepository::class)]
#[ORM\Table(name: 'marketplace_staging')]
#[ORM\UniqueConstraint(name: 'uniq_marketplace_source_record', columns: ['marketplace', 'source_record_id'])]
#[ORM\Index(columns: ['processing_batch_id', 'processing_status'], name: 'idx_staging_batch_status')]
#[ORM\Index(columns: ['company_id', 'processing_status'], name: 'idx_staging_company_status')]
#[ORM\Index(columns: ['marketplace', 'record_type', 'processing_status'], name: 'idx_staging_mp_type_status')]
class MarketplaceStaging
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: ProcessingBatch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProcessingBatch $processingBatch;

    // === MARKETPLACE & SOURCE ===

    /**
     * Маркетплейс (wildberries, ozon, yandex_market, sber_mega_market)
     */
    #[ORM\Column(type: 'string', length: 30)]
    private string $marketplace;

    /**
     * Уникальный ID записи из маркетплейса (srid для WB, operation_id для Ozon)
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $sourceRecordId;

    /**
     * Тип записи (sale, return, cost, storno)
     */
    #[ORM\Column(type: 'string', length: 20, enumType: StagingRecordType::class)]
    private StagingRecordType $recordType;

    // === RAW DATA ===

    /**
     * Оригинальная запись из API (для отладки и переобработки)
     */
    #[ORM\Column(type: 'json')]
    private array $rawData;

    // === PARSED UNIVERSAL FIELDS ===

    /**
     * Сумма операции (универсальное поле)
     */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $amount;

    /**
     * Дата операции (универсальное поле)
     */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $recordDate;

    /**
     * SKU маркетплейса (nm_id для WB, sku для Ozon)
     */
    #[ORM\Column(type: 'string', length: 100)]
    private string $marketplaceSku;

    // === MARKETPLACE-SPECIFIC PARSED DATA ===

    /**
     * Распарсенные данные специфичные для маркетплейса
     * {
     *   "wb": {
     *     "price_without_discount": 1500.00,
     *     "barcode": "1234567890123",
     *     "ts_name": "Размер L"
     *   },
     *   "ozon": {
     *     "posting_number": "12345-0001-1",
     *     "sale_commission": 150.00
     *   }
     * }
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $parsedData = null;

    // === PRODUCT LINKING ===

    /**
     * Связанный листинг маркетплейса (может быть null если не найден)
     */
    #[ORM\ManyToOne(targetEntity: MarketplaceListing::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MarketplaceListing $listing = null;

    /**
     * Связан ли с товаром из каталога
     */
    #[ORM\Column(type: 'boolean')]
    private bool $linkedToProduct = false;

    // === PROCESSING STATUS ===

    /**
     * Статус обработки (pending, processing, completed, failed, skipped)
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ProcessingStatus::class)]
    private ProcessingStatus $processingStatus = ProcessingStatus::PENDING;

    /**
     * Ошибки валидации (JSON массив)
     * [
     *   {"field": "amount", "error": "Amount cannot be negative"},
     *   {"field": "listing", "error": "Listing not found"}
     * ]
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $validationErrors = null;

    // === FINAL ENTITY LINK ===

    /**
     * ID созданной финальной сущности (MarketplaceSale/Return/Cost)
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $finalEntityId = null;

    /**
     * Тип финальной сущности (MarketplaceSale, MarketplaceReturn, MarketplaceCost)
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $finalEntityType = null;

    // === TIMESTAMPS ===

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(
        string $id,
        Company $company,
        ProcessingBatch $processingBatch,
        string $marketplace,
        string $sourceRecordId,
        StagingRecordType $recordType,
        array $rawData,
        string $amount,
        \DateTimeImmutable $recordDate,
        string $marketplaceSku
    ) {
        Assert::uuid($id);
        Assert::notEmpty($marketplace);
        Assert::notEmpty($sourceRecordId);
        Assert::notEmpty($marketplaceSku);

        $this->id = $id;
        $this->company = $company;
        $this->processingBatch = $processingBatch;
        $this->marketplace = $marketplace;
        $this->sourceRecordId = $sourceRecordId;
        $this->recordType = $recordType;
        $this->rawData = $rawData;
        $this->amount = $amount;
        $this->recordDate = $recordDate;
        $this->marketplaceSku = $marketplaceSku;
        $this->createdAt = new \DateTimeImmutable();
    }

    // === GETTERS ===

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getProcessingBatch(): ProcessingBatch
    {
        return $this->processingBatch;
    }

    public function getMarketplace(): string
    {
        return $this->marketplace;
    }

    public function getSourceRecordId(): string
    {
        return $this->sourceRecordId;
    }

    public function getRecordType(): StagingRecordType
    {
        return $this->recordType;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getRecordDate(): \DateTimeImmutable
    {
        return $this->recordDate;
    }

    public function getMarketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function getParsedData(): ?array
    {
        return $this->parsedData;
    }

    public function getListing(): ?MarketplaceListing
    {
        return $this->listing;
    }

    public function isLinkedToProduct(): bool
    {
        return $this->linkedToProduct;
    }

    public function getProcessingStatus(): ProcessingStatus
    {
        return $this->processingStatus;
    }

    public function getValidationErrors(): ?array
    {
        return $this->validationErrors;
    }

    public function getFinalEntityId(): ?string
    {
        return $this->finalEntityId;
    }

    public function getFinalEntityType(): ?string
    {
        return $this->finalEntityType;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    // === SETTERS ===

    public function setParsedData(?array $parsedData): self
    {
        $this->parsedData = $parsedData;
        return $this;
    }

    public function setListing(?MarketplaceListing $listing): self
    {
        $this->listing = $listing;
        $this->linkedToProduct = $listing !== null && $listing->getProduct() !== null;
        return $this;
    }

    public function setValidationErrors(?array $validationErrors): self
    {
        $this->validationErrors = $validationErrors;
        return $this;
    }

    // === STATUS MANAGEMENT ===

    public function markProcessing(): self
    {
        $this->processingStatus = ProcessingStatus::PROCESSING;
        return $this;
    }

    public function markCompleted(string $finalEntityId, string $finalEntityType): self
    {
        $this->processingStatus = ProcessingStatus::COMPLETED;
        $this->finalEntityId = $finalEntityId;
        $this->finalEntityType = $finalEntityType;
        $this->processedAt = new \DateTimeImmutable();
        $this->validationErrors = null;
        return $this;
    }

    public function markFailed(array $validationErrors): self
    {
        $this->processingStatus = ProcessingStatus::FAILED;
        $this->validationErrors = $validationErrors;
        $this->processedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markSkipped(string $reason): self
    {
        $this->processingStatus = ProcessingStatus::SKIPPED;
        $this->validationErrors = [['reason' => $reason]];
        $this->processedAt = new \DateTimeImmutable();
        return $this;
    }

    // === HELPER METHODS ===

    /**
     * Получить поле из parsedData по ключу
     */
    public function getParsedField(string $key, mixed $default = null): mixed
    {
        return $this->parsedData[$key] ?? $default;
    }

    /**
     * Добавить ошибку валидации
     */
    public function addValidationError(string $field, string $error): self
    {
        if ($this->validationErrors === null) {
            $this->validationErrors = [];
        }

        $this->validationErrors[] = [
            'field' => $field,
            'error' => $error,
        ];

        return $this;
    }

    /**
     * Готова ли запись к обработке
     */
    public function isPending(): bool
    {
        return $this->processingStatus === ProcessingStatus::PENDING;
    }

    /**
     * Обработана ли запись успешно
     */
    public function isCompleted(): bool
    {
        return $this->processingStatus === ProcessingStatus::COMPLETED;
    }

    /**
     * Провалилась ли обработка
     */
    public function isFailed(): bool
    {
        return $this->processingStatus === ProcessingStatus::FAILED;
    }
}
