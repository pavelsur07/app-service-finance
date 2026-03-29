<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\AdvertisingType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceAdvertisingCostRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceAdvertisingCostRepository::class)]
#[ORM\Table(name: 'marketplace_advertising_costs')]
#[ORM\UniqueConstraint(
    name: 'uniq_mp_adv_cost',
    columns: ['company_id', 'listing_id', 'date', 'advertising_type', 'external_campaign_id'],
)]
#[ORM\Index(columns: ['company_id'], name: 'idx_mp_adv_cost_company')]
#[ORM\Index(columns: ['company_id', 'date'], name: 'idx_mp_adv_cost_company_date')]
#[ORM\Index(columns: ['listing_id', 'date'], name: 'idx_mp_adv_cost_listing_date')]
class MarketplaceAdvertisingCost
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $listingId;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'string', enumType: AdvertisingType::class)]
    private AdvertisingType $advertisingType;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $amount;

    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $analyticsData;

    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $externalCampaignId;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawData;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $listingId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $date,
        AdvertisingType $advertisingType,
        string $amount = '0.00',
        array $analyticsData = [],
        string $externalCampaignId = '',
        ?array $rawData = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
        Assert::uuid($listingId);
        Assert::numeric($amount);
        Assert::greaterThanEq((float) $amount, 0.0);

        $this->companyId          = $companyId;
        $this->listingId          = $listingId;
        $this->marketplace        = $marketplace;
        $this->date               = $date;
        $this->advertisingType    = $advertisingType;
        $this->amount             = $amount;
        $this->analyticsData      = $analyticsData;
        $this->externalCampaignId = $externalCampaignId;
        $this->rawData            = $rawData;
        $this->createdAt          = new \DateTimeImmutable();
        $this->updatedAt          = new \DateTimeImmutable();
    }

    public function updateAmount(string $amount, array $analyticsData): void
    {
        Assert::numeric($amount);
        Assert::greaterThanEq((float) $amount, 0.0);

        $this->amount        = $amount;
        $this->analyticsData = $analyticsData;
        $this->updatedAt     = new \DateTimeImmutable();
    }

    public function updateRawData(?array $rawData): void
    {
        $this->rawData   = $rawData;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getListingId(): string { return $this->listingId; }
    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getAdvertisingType(): AdvertisingType { return $this->advertisingType; }
    public function getAmount(): string { return $this->amount; }
    public function getAnalyticsData(): array { return $this->analyticsData; }
    public function getExternalCampaignId(): string { return $this->externalCampaignId; }
    public function getRawData(): ?array { return $this->rawData; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
