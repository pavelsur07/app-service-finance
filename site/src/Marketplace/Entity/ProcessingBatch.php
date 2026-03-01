<?php

namespace App\Marketplace\Entity;

use App\Company\Entity\Company;
use App\Marketplace\Repository\ProcessingBatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * ProcessingBatch - партия обработки raw документа
 *
 * Отслеживает:
 * - Сколько записей должно быть обработано
 * - Сколько уже обработано
 * - Сколько failed
 * - Статус обработки
 * - Результаты reconciliation
 */
#[ORM\Entity(repositoryClass: ProcessingBatchRepository::class)]
#[ORM\Table(name: 'marketplace_processing_batch')]
#[ORM\Index(columns: ['company_id', 'status'], name: 'idx_batch_company_status')]
#[ORM\Index(columns: ['raw_document_id'], name: 'idx_batch_raw_doc')]
class ProcessingBatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: MarketplaceRawDocument::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private MarketplaceRawDocument $rawDocument;

    // === СЧЕТЧИКИ ПО ТИПАМ ЗАПИСЕЙ ===

    #[ORM\Column(type: 'integer')]
    private int $totalRecords = 0;

    #[ORM\Column(type: 'integer')]
    private int $salesRecords = 0;

    #[ORM\Column(type: 'integer')]
    private int $returnRecords = 0;

    #[ORM\Column(type: 'integer')]
    private int $costRecords = 0;

    #[ORM\Column(type: 'integer')]
    private int $stornoRecords = 0;

    // === СЧЕТЧИКИ ОБРАБОТКИ ===

    #[ORM\Column(type: 'integer')]
    private int $processedRecords = 0;

    #[ORM\Column(type: 'integer')]
    private int $failedRecords = 0;

    #[ORM\Column(type: 'integer')]
    private int $skippedRecords = 0; // Дубликаты

    // === СТАТУСЫ ===

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'pending'; // pending, parsing, processing, completed, failed

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    // === RECONCILIATION DATA ===

    /**
     * Результаты сверок
     * {
     *   "sales": {"expected": 1000, "actual": 998, "passed": false},
     *   "returns": {"expected": 50, "actual": 50, "passed": true},
     *   "costs": {"unprocessed": 0, "passed": true}
     * }
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $reconciliationData = null;

    // === TIMESTAMPS ===

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        string $id,
        Company $company,
        MarketplaceRawDocument $rawDocument
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->rawDocument = $rawDocument;
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

    public function getRawDocument(): MarketplaceRawDocument
    {
        return $this->rawDocument;
    }

    public function getTotalRecords(): int
    {
        return $this->totalRecords;
    }

    public function getSalesRecords(): int
    {
        return $this->salesRecords;
    }

    public function getReturnRecords(): int
    {
        return $this->returnRecords;
    }

    public function getCostRecords(): int
    {
        return $this->costRecords;
    }

    public function getStornoRecords(): int
    {
        return $this->stornoRecords;
    }

    public function getProcessedRecords(): int
    {
        return $this->processedRecords;
    }

    public function getFailedRecords(): int
    {
        return $this->failedRecords;
    }

    public function getSkippedRecords(): int
    {
        return $this->skippedRecords;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getReconciliationData(): ?array
    {
        return $this->reconciliationData;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    // === SETTERS ===

    public function setTotalRecords(int $totalRecords): self
    {
        $this->totalRecords = $totalRecords;
        return $this;
    }

    public function setSalesRecords(int $salesRecords): self
    {
        $this->salesRecords = $salesRecords;
        return $this;
    }

    public function setReturnRecords(int $returnRecords): self
    {
        $this->returnRecords = $returnRecords;
        return $this;
    }

    public function setCostRecords(int $costRecords): self
    {
        $this->costRecords = $costRecords;
        return $this;
    }

    public function setStornoRecords(int $stornoRecords): self
    {
        $this->stornoRecords = $stornoRecords;
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function setReconciliationData(?array $reconciliationData): self
    {
        $this->reconciliationData = $reconciliationData;
        return $this;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    // === INCREMENT METHODS ===

    public function incrementProcessedRecords(): self
    {
        $this->processedRecords++;
        return $this;
    }

    public function incrementFailedRecords(): self
    {
        $this->failedRecords++;
        return $this;
    }

    public function incrementSkippedRecords(): self
    {
        $this->skippedRecords++;
        return $this;
    }

    // === STATUS METHODS ===

    public function markStarted(): self
    {
        $this->status = 'processing';
        $this->startedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markCompleted(): self
    {
        $this->status = 'completed';
        $this->completedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markFailed(string $errorMessage): self
    {
        $this->status = 'failed';
        $this->errorMessage = $errorMessage;
        $this->completedAt = new \DateTimeImmutable();
        return $this;
    }

    // === RECONCILIATION METHODS ===

    /**
     * Получить сводку по reconciliation
     */
    public function getReconciliation(): array
    {
        return [
            'expected' => $this->totalRecords,
            'processed' => $this->processedRecords,
            'failed' => $this->failedRecords,
            'skipped' => $this->skippedRecords,
            'missing' => $this->getMissingRecords(),
            'is_complete' => $this->isComplete(),
        ];
    }

    /**
     * Все ли записи обработаны (processed + failed + skipped = total)
     */
    public function isComplete(): bool
    {
        return ($this->processedRecords + $this->failedRecords + $this->skippedRecords) === $this->totalRecords;
    }

    /**
     * Сколько записей не обработано
     */
    public function getMissingRecords(): int
    {
        return $this->totalRecords - ($this->processedRecords + $this->failedRecords + $this->skippedRecords);
    }

    /**
     * Процент выполнения
     */
    public function getProgressPercent(): float
    {
        if ($this->totalRecords === 0) {
            return 0.0;
        }

        return round(
            (($this->processedRecords + $this->skippedRecords) / $this->totalRecords) * 100,
            2
        );
    }

    /**
     * Есть ли ошибки обработки
     */
    public function hasFailures(): bool
    {
        return $this->failedRecords > 0;
    }
}
