<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Entity;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: AdRawDocumentRepository::class)]
#[ORM\Table(name: 'marketplace_ad_raw_documents')]
#[ORM\UniqueConstraint(
    name: 'uq_ad_raw_document_company_marketplace_date',
    columns: ['company_id', 'marketplace', 'report_date'],
)]
#[ORM\Index(columns: ['company_id'], name: 'idx_ad_raw_document_company')]
#[ORM\Index(columns: ['company_id', 'marketplace'], name: 'idx_ad_raw_document_company_marketplace')]
class AdRawDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 50, enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $reportDate;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $loadedAt;

    #[ORM\Column(type: 'text')]
    private string $rawPayload;

    #[ORM\Column(type: 'string', length: 20, enumType: AdRawDocumentStatus::class, options: ['default' => 'draft'])]
    private AdRawDocumentStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $processingError = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
        string $rawPayload,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
        Assert::notEmpty($rawPayload);

        $this->companyId = $companyId;
        $this->marketplace = $marketplace;
        $this->reportDate = $reportDate;
        $this->rawPayload = $rawPayload;
        $this->status = AdRawDocumentStatus::DRAFT;
        $this->loadedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsProcessed(): void
    {
        if (AdRawDocumentStatus::PROCESSED === $this->status) {
            throw new \DomainException('Документ уже обработан.');
        }

        $this->status = AdRawDocumentStatus::PROCESSED;
        $this->processingError = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Помечает документ как FAILED с сохранением причины.
     *
     * Терминальный статус: повторная обработка невозможна без reset/update.
     * Guard-метод для case'ов, когда AdRawDocument уже managed в UoW. В
     * async-хендлере ProcessAdRawDocumentHandler используется не этот метод,
     * а атомарный DBAL-update через Repository::markFailedWithReason — он
     * идемпотентен и не требует флаша.
     */
    public function markFailed(string $reason): void
    {
        Assert::notEmpty($reason, 'Причина ошибки не может быть пустой.');

        if (AdRawDocumentStatus::PROCESSED === $this->status) {
            throw new \DomainException('Нельзя пометить PROCESSED-документ как FAILED.');
        }

        $this->status = AdRawDocumentStatus::FAILED;
        $this->processingError = $reason;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function resetToDraft(): void
    {
        if (AdRawDocumentStatus::DRAFT === $this->status) {
            throw new \DomainException('Документ уже в статусе черновик.');
        }

        $this->status = AdRawDocumentStatus::DRAFT;
        $this->processingError = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updatePayload(string $rawPayload): void
    {
        $this->rawPayload = $rawPayload;
        $this->status = AdRawDocumentStatus::DRAFT;
        $this->processingError = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getReportDate(): \DateTimeImmutable
    {
        return $this->reportDate;
    }

    public function getLoadedAt(): \DateTimeImmutable
    {
        return $this->loadedAt;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    public function getStatus(): AdRawDocumentStatus
    {
        return $this->status;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
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
