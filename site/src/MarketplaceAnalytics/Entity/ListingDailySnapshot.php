<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Entity;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: \App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepository::class)]
#[ORM\Table(name: 'listing_daily_snapshots')]
#[ORM\UniqueConstraint(
    name: 'uniq_snapshot_company_listing_date',
    columns: ['company_id', 'listing_id', 'snapshot_date'],
)]
#[ORM\Index(columns: ['company_id'], name: 'idx_snapshot_company')]
#[ORM\Index(columns: ['company_id', 'snapshot_date'], name: 'idx_snapshot_company_date')]
#[ORM\Index(columns: ['company_id', 'marketplace', 'snapshot_date'], name: 'idx_snapshot_company_marketplace_date')]
#[ORM\Index(columns: ['listing_id', 'snapshot_date'], name: 'idx_snapshot_listing_date')]
class ListingDailySnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $listingId;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class, length: 50)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $snapshotDate;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, options: ['default' => '0.00'])]
    private string $revenue = '0.00';

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, options: ['default' => '0.00'])]
    private string $refunds = '0.00';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $salesQuantity = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $returnsQuantity = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $ordersQuantity = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $deliveredQuantity = 0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $avgSalePrice = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $costPrice = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $totalCostPrice = null;

    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $costBreakdown = [];

    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $advertisingDetails = [];

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $dataQuality = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $calculatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $companyId,
        string $listingId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $snapshotDate,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($listingId);

        $this->id           = $id;
        $this->companyId    = $companyId;
        $this->listingId    = $listingId;
        $this->marketplace  = $marketplace;
        $this->snapshotDate = $snapshotDate;
        $this->calculatedAt = new \DateTimeImmutable();
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function recalculate(
        string $revenue,
        string $refunds,
        int $salesQuantity,
        int $returnsQuantity,
        int $ordersQuantity,
        int $deliveredQuantity,
        string $avgSalePrice,
        ?string $costPrice,
        ?string $totalCostPrice,
        array $costBreakdown,
        array $advertisingDetails,
        array $dataQuality,
    ): void {
        $this->revenue             = $revenue;
        $this->refunds             = $refunds;
        $this->salesQuantity       = $salesQuantity;
        $this->returnsQuantity     = $returnsQuantity;
        $this->ordersQuantity      = $ordersQuantity;
        $this->deliveredQuantity   = $deliveredQuantity;
        $this->avgSalePrice        = $avgSalePrice;
        $this->costPrice           = $costPrice;
        $this->totalCostPrice      = $totalCostPrice;
        $this->costBreakdown       = $costBreakdown;
        $this->advertisingDetails  = $advertisingDetails;
        $this->dataQuality         = $dataQuality;
        $this->calculatedAt        = new \DateTimeImmutable();
        $this->updatedAt           = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getListingId(): string { return $this->listingId; }
    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getSnapshotDate(): \DateTimeImmutable { return $this->snapshotDate; }
    public function getRevenue(): string { return $this->revenue; }
    public function getRefunds(): string { return $this->refunds; }
    public function getSalesQuantity(): int { return $this->salesQuantity; }
    public function getReturnsQuantity(): int { return $this->returnsQuantity; }
    public function getOrdersQuantity(): int { return $this->ordersQuantity; }
    public function getDeliveredQuantity(): int { return $this->deliveredQuantity; }
    public function getAvgSalePrice(): string { return $this->avgSalePrice; }
    public function getCostPrice(): ?string { return $this->costPrice; }
    public function getTotalCostPrice(): ?string { return $this->totalCostPrice; }
    public function getCostBreakdown(): array { return $this->costBreakdown; }
    public function getAdvertisingDetails(): array { return $this->advertisingDetails; }
    public function getDataQuality(): array { return $this->dataQuality; }
    public function getCalculatedAt(): \DateTimeImmutable { return $this->calculatedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
