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

    public function build(): ListingDailySnapshot
    {
        $snapshot = new ListingDailySnapshot(
            id: $this->id,
            companyId: $this->companyId,
            listingId: $this->listingId,
            marketplace: $this->marketplace,
            snapshotDate: $this->snapshotDate,
        );

        if ($this->costPrice !== null || $this->salesQuantity !== 0) {
            $snapshot->recalculate(
                revenue: '0.00',
                refunds: '0.00',
                salesQuantity: $this->salesQuantity,
                returnsQuantity: 0,
                ordersQuantity: 0,
                deliveredQuantity: 0,
                avgSalePrice: '0.00',
                costPrice: $this->costPrice,
                totalCostPrice: null,
                costBreakdown: [],
                advertisingDetails: [],
                dataQuality: [],
            );
        }

        return $snapshot;
    }
}
