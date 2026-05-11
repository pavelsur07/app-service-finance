<?php

declare(strict_types=1);

namespace App\Tests\Builders\Inventory;

use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Enum\MarketplaceType;

final class StockSnapshotBuilder
{
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_SNAPSHOT_SESSION_ID = '22222222-2222-2222-2222-222222222222';
    public const DEFAULT_LOCATION_ID = '33333333-3333-3333-3333-333333333333';
    public const DEFAULT_RAW_SNAPSHOT_ID = '44444444-4444-4444-4444-444444444444';
    public const DEFAULT_LISTING_ID = '55555555-5555-5555-5555-555555555555';
    public const DEFAULT_PRODUCT_ID = '66666666-6666-6666-6666-666666666666';

    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $snapshotSessionId = self::DEFAULT_SNAPSHOT_SESSION_ID;
    private \DateTimeImmutable $snapshotDate;
    private \DateTimeImmutable $snapshotAt;
    private ?string $listingId = self::DEFAULT_LISTING_ID;
    private ?string $productId = self::DEFAULT_PRODUCT_ID;
    private string $locationId = self::DEFAULT_LOCATION_ID;
    private StockStatus $status = StockStatus::Available;
    private string $quantity = '12.345';
    private string $reservedQuantity = '0.000';
    private MarketplaceType $source = MarketplaceType::WILDBERRIES;
    private ?string $sourceSku = null;
    private ?string $sourceOfferId = null;
    private ?string $fulfillmentType = null;
    private StockSnapshotMappingStatus $mappingStatus = StockSnapshotMappingStatus::Unmapped;
    private string $rawSnapshotId = self::DEFAULT_RAW_SNAPSHOT_ID;

    private function __construct()
    {
        $this->snapshotDate = new \DateTimeImmutable('2026-04-20');
        $this->snapshotAt = new \DateTimeImmutable('2026-04-20T15:30:00+00:00');
    }

    public static function aStockSnapshot(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withSnapshotSessionId(string $snapshotSessionId): self
    {
        $clone = clone $this;
        $clone->snapshotSessionId = $snapshotSessionId;

        return $clone;
    }

    public function withSnapshotDate(\DateTimeImmutable $snapshotDate): self
    {
        $clone = clone $this;
        $clone->snapshotDate = $snapshotDate;

        return $clone;
    }

    public function withSnapshotAt(\DateTimeImmutable $snapshotAt): self
    {
        $clone = clone $this;
        $clone->snapshotAt = $snapshotAt;

        return $clone;
    }

    public function withListingId(?string $listingId): self
    {
        $clone = clone $this;
        $clone->listingId = $listingId;

        return $clone;
    }

    public function withProductId(?string $productId): self
    {
        $clone = clone $this;
        $clone->productId = $productId;

        return $clone;
    }

    public function withLocationId(string $locationId): self
    {
        $clone = clone $this;
        $clone->locationId = $locationId;

        return $clone;
    }

    public function withStatus(StockStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withQuantity(string $quantity): self
    {
        $clone = clone $this;
        $clone->quantity = $quantity;

        return $clone;
    }

    public function withSource(MarketplaceType $source): self
    {
        $clone = clone $this;
        $clone->source = $source;

        return $clone;
    }

    public function withReservedQuantity(string $reservedQuantity): self
    {
        $clone = clone $this;
        $clone->reservedQuantity = $reservedQuantity;

        return $clone;
    }

    public function withSourceSku(?string $sourceSku): self
    {
        $clone = clone $this;
        $clone->sourceSku = $sourceSku;

        return $clone;
    }

    public function withSourceOfferId(?string $sourceOfferId): self
    {
        $clone = clone $this;
        $clone->sourceOfferId = $sourceOfferId;

        return $clone;
    }

    public function withFulfillmentType(?string $fulfillmentType): self
    {
        $clone = clone $this;
        $clone->fulfillmentType = $fulfillmentType;

        return $clone;
    }

    public function withMappingStatus(StockSnapshotMappingStatus $mappingStatus): self
    {
        $clone = clone $this;
        $clone->mappingStatus = $mappingStatus;

        return $clone;
    }

    public function withRawSnapshotId(string $rawSnapshotId): self
    {
        $clone = clone $this;
        $clone->rawSnapshotId = $rawSnapshotId;

        return $clone;
    }

    public function build(): StockSnapshot
    {
        return new StockSnapshot(
            companyId: $this->companyId,
            snapshotSessionId: $this->snapshotSessionId,
            snapshotDate: $this->snapshotDate,
            snapshotAt: $this->snapshotAt,
            locationId: $this->locationId,
            status: $this->status,
            quantity: $this->quantity,
            reservedQuantity: $this->reservedQuantity,
            source: $this->source,
            rawSnapshotId: $this->rawSnapshotId,
            listingId: $this->listingId,
            productId: $this->productId,
            sourceSku: $this->sourceSku,
            sourceOfferId: $this->sourceOfferId,
            fulfillmentType: $this->fulfillmentType,
            mappingStatus: $this->mappingStatus,
        );
    }
}
