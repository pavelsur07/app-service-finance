<?php

declare(strict_types=1);

namespace App\Tests\Builders\MarketplaceAnalytics;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;

final class ListingDailySnapshotBuilder
{
    public const DEFAULT_ID = '66666666-6666-6666-6666-666666666666';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_LISTING_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $listingId = self::DEFAULT_LISTING_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private \DateTimeImmutable $snapshotDate;
    private ?string $costPrice = null;
    private int $salesQuantity = 0;
    private string $revenue = '0.00';
    private string $refunds = '0.00';
    private int $returnsQuantity = 0;
    private int $ordersQuantity = 0;
    private int $deliveredQuantity = 0;
    private string $avgSalePrice = '0.00';
    private ?string $totalCostPrice = null;
    private array $costBreakdown = [];
    private array $advertisingDetails = [];
    private array $dataQuality = [];

    private function __construct()
    {
        $this->snapshotDate = new \DateTimeImmutable('2026-01-15');
    }

    public static function aSnapshot(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('66666666-6666-6666-6666-%012d', $index);
        $clone->listingId = sprintf('aaaaaaaa-aaaa-aaaa-aaaa-%012d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withListingId(string $listingId): self
    {
        $clone = clone $this;
        $clone->listingId = $listingId;

        return $clone;
    }

    public function withSnapshotDate(\DateTimeImmutable $snapshotDate): self
    {
        $clone = clone $this;
        $clone->snapshotDate = $snapshotDate;

        return $clone;
    }

    public function withCostPrice(string $costPrice): self
    {
        $clone = clone $this;
        $clone->costPrice = $costPrice;

        return $clone;
    }

    public function withoutCostPrice(): self
    {
        $clone = clone $this;
        $clone->costPrice = null;

        return $clone;
    }

    public function withSalesQuantity(int $salesQuantity): self
    {
        $clone = clone $this;
        $clone->salesQuantity = $salesQuantity;

        return $clone;
    }

    public function withRevenue(string $revenue): self
    {
        $clone = clone $this;
        $clone->revenue = $revenue;

        return $clone;
    }

    public function withRefunds(string $refunds): self
    {
        $clone = clone $this;
        $clone->refunds = $refunds;

        return $clone;
    }

    public function withReturnsQuantity(int $returnsQuantity): self
    {
        $clone = clone $this;
        $clone->returnsQuantity = $returnsQuantity;

        return $clone;
    }

    public function withOrdersQuantity(int $ordersQuantity): self
    {
        $clone = clone $this;
        $clone->ordersQuantity = $ordersQuantity;

        return $clone;
    }

    public function withDeliveredQuantity(int $deliveredQuantity): self
    {
        $clone = clone $this;
        $clone->deliveredQuantity = $deliveredQuantity;

        return $clone;
    }

    public function withAvgSalePrice(string $avgSalePrice): self
    {
        $clone = clone $this;
        $clone->avgSalePrice = $avgSalePrice;

        return $clone;
    }

    public function withTotalCostPrice(string $totalCostPrice): self
    {
        $clone = clone $this;
        $clone->totalCostPrice = $totalCostPrice;

        return $clone;
    }

    public function withCostBreakdown(array $costBreakdown): self
    {
        $clone = clone $this;
        $clone->costBreakdown = $costBreakdown;

        return $clone;
    }

    public function withAdvertisingDetails(array $advertisingDetails): self
    {
        $clone = clone $this;
        $clone->advertisingDetails = $advertisingDetails;

        return $clone;
    }

    public function withDataQuality(array $dataQuality): self
    {
        $clone = clone $this;
        $clone->dataQuality = $dataQuality;

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function build(): ListingDailySnapshot
    {
        $snapshot = new ListingDailySnapshot(
            id: $this->id,
            companyId: $this->companyId,
            listingId: $this->listingId,
            marketplace: $this->marketplace,
            snapshotDate: $this->snapshotDate,
        );

        $snapshot->recalculate(
            revenue: $this->revenue,
            refunds: $this->refunds,
            salesQuantity: $this->salesQuantity,
            returnsQuantity: $this->returnsQuantity,
            ordersQuantity: $this->ordersQuantity,
            deliveredQuantity: $this->deliveredQuantity,
            avgSalePrice: $this->avgSalePrice,
            costPrice: $this->costPrice,
            totalCostPrice: $this->totalCostPrice,
            costBreakdown: $this->costBreakdown,
            advertisingDetails: $this->advertisingDetails,
            dataQuality: $this->dataQuality,
        );

        return $snapshot;
    }
}
