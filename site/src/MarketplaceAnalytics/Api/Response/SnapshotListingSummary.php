<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

use App\MarketplaceAnalytics\DTO\ListingUnitEconomics;

final readonly class SnapshotListingSummary
{
    public function __construct(
        private string $listingId,
        private ?string $listingName,
        private string $marketplaceSku,
        private string $marketplace,
        private string $revenue,
        private string $refunds,
        private ?string $costPrice,
        private ?string $profitTotal,
        private ?float $roi,
        private int $salesQuantity,
        private int $returnsQuantity,
        private int $ordersQuantity,
        private array $dataQuality,
    ) {}

    public static function fromDTO(ListingUnitEconomics $dto): self
    {
        return new self(
            listingId: $dto->listingId,
            listingName: $dto->listingName,
            marketplaceSku: $dto->marketplaceSku,
            marketplace: $dto->marketplaceType,
            revenue: $dto->revenue,
            refunds: $dto->refunds,
            costPrice: $dto->costPrice,
            profitTotal: $dto->profitTotal,
            roi: $dto->roi,
            salesQuantity: $dto->salesQuantity,
            returnsQuantity: $dto->returnsQuantity,
            ordersQuantity: $dto->ordersQuantity,
            dataQuality: $dto->dataQuality->toArray(),
        );
    }

    public function toArray(): array
    {
        return [
            'listing_id' => $this->listingId,
            'listing_name' => $this->listingName,
            'marketplace_sku' => $this->marketplaceSku,
            'marketplace' => $this->marketplace,
            'revenue' => $this->revenue,
            'refunds' => $this->refunds,
            'cost_price' => $this->costPrice,
            'profit_total' => $this->profitTotal,
            'roi' => $this->roi,
            'sales_quantity' => $this->salesQuantity,
            'returns_quantity' => $this->returnsQuantity,
            'orders_quantity' => $this->ordersQuantity,
            'data_quality' => $this->dataQuality,
        ];
    }
}
