<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;

final readonly class SnapshotResponse
{
    public function __construct(
        private string $id,
        private string $listingId,
        private string $listingName,
        private string $listingSku,
        private string $marketplace,
        private string $snapshotDate,
        private string $revenue,
        private string $refunds,
        private int $salesQuantity,
        private int $returnsQuantity,
        private int $ordersQuantity,
        private int $deliveredQuantity,
        private string $avgSalePrice,
        private ?string $costPrice,
        private ?string $totalCostPrice,
        private array $costBreakdown,
        private array $advertisingDetails,
        private array $dataQuality,
        private string $calculatedAt,
    ) {}

    public static function fromEntity(
        ListingDailySnapshot $snapshot,
        string $name,
        string $sku,
    ): self {
        return new self(
            id: $snapshot->getId(),
            listingId: $snapshot->getListingId(),
            listingName: $name,
            listingSku: $sku,
            marketplace: $snapshot->getMarketplace()->value,
            snapshotDate: $snapshot->getSnapshotDate()->format('Y-m-d'),
            revenue: $snapshot->getRevenue(),
            refunds: $snapshot->getRefunds(),
            salesQuantity: $snapshot->getSalesQuantity(),
            returnsQuantity: $snapshot->getReturnsQuantity(),
            ordersQuantity: $snapshot->getOrdersQuantity(),
            deliveredQuantity: $snapshot->getDeliveredQuantity(),
            avgSalePrice: $snapshot->getAvgSalePrice(),
            costPrice: $snapshot->getCostPrice(),
            totalCostPrice: $snapshot->getTotalCostPrice(),
            costBreakdown: $snapshot->getCostBreakdown(),
            advertisingDetails: $snapshot->getAdvertisingDetails(),
            dataQuality: $snapshot->getDataQuality(),
            calculatedAt: $snapshot->getCalculatedAt()->format(\DATE_ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'listing_id' => $this->listingId,
            'listing_name' => $this->listingName,
            'listing_sku' => $this->listingSku,
            'marketplace' => $this->marketplace,
            'snapshot_date' => $this->snapshotDate,
            'revenue' => $this->revenue,
            'refunds' => $this->refunds,
            'sales_quantity' => $this->salesQuantity,
            'returns_quantity' => $this->returnsQuantity,
            'orders_quantity' => $this->ordersQuantity,
            'delivered_quantity' => $this->deliveredQuantity,
            'avg_sale_price' => $this->avgSalePrice,
            'cost_price' => $this->costPrice,
            'total_cost_price' => $this->totalCostPrice,
            'cost_breakdown' => $this->costBreakdown,
            'advertising_details' => $this->advertisingDetails,
            'data_quality' => $this->dataQuality,
            'calculated_at' => $this->calculatedAt,
        ];
    }
}
