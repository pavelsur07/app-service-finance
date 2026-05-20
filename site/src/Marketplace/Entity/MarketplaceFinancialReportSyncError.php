<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Repository\MarketplaceFinancialReportSyncErrorRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceFinancialReportSyncErrorRepository::class)]
#[ORM\Table(name: 'marketplace_financial_report_sync_errors')]
#[ORM\Index(name: 'idx_mfrse_status_created', columns: ['sync_status_id', 'created_at'])]
#[ORM\Index(name: 'idx_mfrse_company_date', columns: ['company_id', 'business_date'])]
#[ORM\Index(name: 'idx_mfrse_connection_date', columns: ['connection_id', 'business_date'])]
class MarketplaceFinancialReportSyncError
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $syncStatusId;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $connectionId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $businessDate;

    #[ORM\Column(type: 'string', length: 255)]
    private string $errorClass;

    #[ORM\Column(type: 'text')]
    private string $errorMessage;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $statusCode;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $responseExcerpt;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $requestPayload;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $requestPayload
     */
    public function __construct(
        string $id,
        string $syncStatusId,
        string $companyId,
        string $connectionId,
        \DateTimeImmutable $businessDate,
        string $errorClass,
        string $errorMessage,
        ?int $statusCode = null,
        ?string $responseExcerpt = null,
        ?array $requestPayload = null,
    ) {
        Assert::uuid($id);
        Assert::uuid($syncStatusId);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);

        $this->id = $id;
        $this->syncStatusId = $syncStatusId;
        $this->companyId = $companyId;
        $this->connectionId = $connectionId;
        $this->businessDate = $businessDate;
        $this->errorClass = $errorClass;
        $this->errorMessage = $errorMessage;
        $this->statusCode = $statusCode;
        $this->responseExcerpt = $responseExcerpt;
        $this->requestPayload = $requestPayload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    public function getSyncStatusId(): string { return $this->syncStatusId; }

    public function getCompanyId(): string { return $this->companyId; }

    public function getConnectionId(): string { return $this->connectionId; }

    public function getBusinessDate(): \DateTimeImmutable { return $this->businessDate; }

    public function getErrorClass(): string { return $this->errorClass; }

    public function getErrorMessage(): string { return $this->errorMessage; }

    public function getStatusCode(): ?int { return $this->statusCode; }

    public function getResponseExcerpt(): ?string { return $this->responseExcerpt; }

    /**
     * @return array<string, mixed>|null
     */
    public function getRequestPayload(): ?array { return $this->requestPayload; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
