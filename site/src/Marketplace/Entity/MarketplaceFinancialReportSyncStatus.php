<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceFinancialReportSyncStatusRepository::class)]
#[ORM\Table(name: 'marketplace_financial_report_sync_statuses')]
#[ORM\UniqueConstraint(name: 'uniq_mfrss_connection_report_day', columns: ['connection_id', 'report_type', 'business_date'])]
#[ORM\Index(name: 'idx_mfrss_company_connection_date', columns: ['company_id', 'connection_id', 'business_date'])]
#[ORM\Index(name: 'idx_mfrss_connection_report_date', columns: ['connection_id', 'report_type', 'business_date'])]
class MarketplaceFinancialReportSyncStatus
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $connectionId;

    #[ORM\Column(type: 'string', length: 16, enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'string', length: 64)]
    private string $reportType;

    #[ORM\Column(type: 'string', length: 128)]
    private string $apiEndpoint;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $businessDate;

    #[ORM\Column(type: 'string', length: 32, enumType: FinancialReportSyncStatus::class)]
    private FinancialReportSyncStatus $status;

    #[ORM\Column(type: 'string', length: 32, enumType: FinancialReportSyncMode::class, nullable: true)]
    private ?FinancialReportSyncMode $mode;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $rawDocumentId = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $recordsCount = 0;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $rowsHash = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextRetryAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastEmptyAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $lastErrorClass = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $lastErrorStatusCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorResponseExcerpt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $companyId,
        string $connectionId,
        MarketplaceType $marketplace,
        string $reportType,
        string $apiEndpoint,
        \DateTimeImmutable $businessDate,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);

        $now = new \DateTimeImmutable();

        $this->id = $id;
        $this->companyId = $companyId;
        $this->connectionId = $connectionId;
        $this->marketplace = $marketplace;
        $this->reportType = $reportType;
        $this->apiEndpoint = $apiEndpoint;
        $this->businessDate = $businessDate;
        $this->status = FinancialReportSyncStatus::QUEUED;
        $this->mode = null;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getConnectionId(): string { return $this->connectionId; }
    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getReportType(): string { return $this->reportType; }
    public function getApiEndpoint(): string { return $this->apiEndpoint; }
    public function getBusinessDate(): \DateTimeImmutable { return $this->businessDate; }
    public function getStatus(): FinancialReportSyncStatus { return $this->status; }
    public function getMode(): ?FinancialReportSyncMode { return $this->mode; }
    public function getRawDocumentId(): ?string { return $this->rawDocumentId; }
    public function getRecordsCount(): int { return $this->recordsCount; }
    public function getRowsHash(): ?string { return $this->rowsHash; }
    public function getAttempts(): int { return $this->attempts; }
    public function getLastAttemptAt(): ?\DateTimeImmutable { return $this->lastAttemptAt; }
    public function getNextRetryAt(): ?\DateTimeImmutable { return $this->nextRetryAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getLastSuccessAt(): ?\DateTimeImmutable { return $this->lastSuccessAt; }
    public function getLastEmptyAt(): ?\DateTimeImmutable { return $this->lastEmptyAt; }
    public function getLastErrorClass(): ?string { return $this->lastErrorClass; }
    public function getLastErrorMessage(): ?string { return $this->lastErrorMessage; }
    public function getLastErrorStatusCode(): ?int { return $this->lastErrorStatusCode; }
    public function getLastErrorResponseExcerpt(): ?string { return $this->lastErrorResponseExcerpt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
