<?php

namespace App\Marketplace\Entity;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceRawDocumentRepository::class)]
#[ORM\Table(name: 'marketplace_raw_documents')]
#[ORM\Index(columns: ['company_id', 'synced_at'], name: 'idx_company_synced')]
#[ORM\Index(columns: ['marketplace', 'document_type'], name: 'idx_marketplace_type')]
class MarketplaceRawDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(length: 50)]
    private string $documentType; // "sales_report", "costs_report", "returns_report"

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'json')]
    private array $rawData; // Полный JSON ответ от API

    #[ORM\Column(length: 255)]
    private string $apiEndpoint; // Какой endpoint вызывали

    #[ORM\Column(type: 'integer')]
    private int $recordsCount = 0; // Сколько записей обработано

    #[ORM\Column(type: 'integer')]
    private int $recordsCreated = 0; // Сколько создано новых

    #[ORM\Column(type: 'integer')]
    private int $recordsSkipped = 0; // Сколько пропущено (дубликаты)

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $syncedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $syncNotes = null; // Заметки о синхронизации (ошибки, предупреждения)

    public function __construct(
        string $id,
        Company $company,
        MarketplaceType $marketplace,
        string $documentType,
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->marketplace = $marketplace;
        $this->documentType = $documentType;
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function setPeriodFrom(\DateTimeImmutable $periodFrom): self
    {
        $this->periodFrom = $periodFrom;

        return $this;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function setPeriodTo(\DateTimeImmutable $periodTo): self
    {
        $this->periodTo = $periodTo;

        return $this;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function setRawData(array $rawData): self
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getApiEndpoint(): string
    {
        return $this->apiEndpoint;
    }

    public function setApiEndpoint(string $apiEndpoint): self
    {
        $this->apiEndpoint = $apiEndpoint;

        return $this;
    }

    public function getRecordsCount(): int
    {
        return $this->recordsCount;
    }

    public function setRecordsCount(int $recordsCount): self
    {
        $this->recordsCount = $recordsCount;

        return $this;
    }

    public function getRecordsCreated(): int
    {
        return $this->recordsCreated;
    }

    public function setRecordsCreated(int $recordsCreated): self
    {
        $this->recordsCreated = $recordsCreated;

        return $this;
    }

    public function incrementRecordsCreated(): self
    {
        ++$this->recordsCreated;

        return $this;
    }

    public function getRecordsSkipped(): int
    {
        return $this->recordsSkipped;
    }

    public function setRecordsSkipped(int $recordsSkipped): self
    {
        $this->recordsSkipped = $recordsSkipped;

        return $this;
    }

    public function incrementRecordsSkipped(): self
    {
        ++$this->recordsSkipped;

        return $this;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function getSyncNotes(): ?string
    {
        return $this->syncNotes;
    }

    public function setSyncNotes(?string $syncNotes): self
    {
        $this->syncNotes = $syncNotes;

        return $this;
    }

    public function addSyncNote(string $note): self
    {
        if ($this->syncNotes) {
            $this->syncNotes .= "\n".$note;
        } else {
            $this->syncNotes = $note;
        }

        return $this;
    }
}
