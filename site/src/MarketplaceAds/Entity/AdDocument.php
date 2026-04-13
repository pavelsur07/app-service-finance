<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Entity;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Repository\AdDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: AdDocumentRepository::class)]
#[ORM\Table(name: 'marketplace_ad_documents')]
#[ORM\UniqueConstraint(
    name: 'uq_ad_document_company_marketplace_date_campaign_sku',
    columns: ['company_id', 'marketplace', 'report_date', 'campaign_id', 'parent_sku'],
)]
#[ORM\Index(columns: ['company_id'], name: 'idx_ad_document_company')]
#[ORM\Index(columns: ['company_id', 'report_date'], name: 'idx_ad_document_company_date')]
#[ORM\Index(columns: ['ad_raw_document_id'], name: 'idx_ad_document_raw')]
class AdDocument
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

    #[ORM\Column(type: 'string', length: 255)]
    private string $campaignId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $campaignName;

    #[ORM\Column(type: 'string', length: 255)]
    private string $parentSku;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $totalCost;

    #[ORM\Column(type: 'integer')]
    private int $totalImpressions;

    #[ORM\Column(type: 'integer')]
    private int $totalClicks;

    #[ORM\Column(type: 'guid')]
    private string $adRawDocumentId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
        string $campaignId,
        string $campaignName,
        string $parentSku,
        string $totalCost,
        int $totalImpressions,
        int $totalClicks,
        string $adRawDocumentId,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
        Assert::uuid($adRawDocumentId);
        Assert::notEmpty($campaignId);
        Assert::notEmpty($campaignName);
        Assert::notEmpty($parentSku);

        $this->companyId = $companyId;
        $this->marketplace = $marketplace;
        $this->reportDate = $reportDate;
        $this->campaignId = $campaignId;
        $this->campaignName = $campaignName;
        $this->parentSku = $parentSku;
        $this->totalCost = $totalCost;
        $this->totalImpressions = $totalImpressions;
        $this->totalClicks = $totalClicks;
        $this->adRawDocumentId = $adRawDocumentId;
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

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getReportDate(): \DateTimeImmutable
    {
        return $this->reportDate;
    }

    public function getCampaignId(): string
    {
        return $this->campaignId;
    }

    public function getCampaignName(): string
    {
        return $this->campaignName;
    }

    public function getParentSku(): string
    {
        return $this->parentSku;
    }

    public function getTotalCost(): string
    {
        return $this->totalCost;
    }

    public function getTotalImpressions(): int
    {
        return $this->totalImpressions;
    }

    public function getTotalClicks(): int
    {
        return $this->totalClicks;
    }

    public function getAdRawDocumentId(): string
    {
        return $this->adRawDocumentId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
