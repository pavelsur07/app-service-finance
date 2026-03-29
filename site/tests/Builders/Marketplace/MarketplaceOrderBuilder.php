<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\MarketplaceOrder;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OrderStatus;

final class MarketplaceOrderBuilder
{
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_LISTING_ID = '22222222-2222-2222-2222-222222222222';

    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $listingId = self::DEFAULT_LISTING_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private string $externalOrderId = 'WB-ORDER-001';
    private \DateTimeImmutable $orderDate;
    private int $quantity = 1;
    private OrderStatus $status = OrderStatus::ORDERED;
    private ?string $rawDocumentId = null;
    private ?array $rawData = null;

    private function __construct()
    {
        $this->orderDate = new \DateTimeImmutable('2026-01-15');
    }

    public static function aOrder(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->listingId = sprintf('22222222-2222-2222-2222-%012d', $index);
        $clone->externalOrderId = sprintf('WB-ORDER-%03d', $index);

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

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withExternalOrderId(string $externalOrderId): self
    {
        $clone = clone $this;
        $clone->externalOrderId = $externalOrderId;

        return $clone;
    }

    public function withOrderDate(\DateTimeImmutable $orderDate): self
    {
        $clone = clone $this;
        $clone->orderDate = $orderDate;

        return $clone;
    }

    public function withQuantity(int $quantity): self
    {
        $clone = clone $this;
        $clone->quantity = $quantity;

        return $clone;
    }

    public function withRawDocumentId(?string $rawDocumentId): self
    {
        $clone = clone $this;
        $clone->rawDocumentId = $rawDocumentId;

        return $clone;
    }

    public function withRawData(?array $rawData): self
    {
        $clone = clone $this;
        $clone->rawData = $rawData;

        return $clone;
    }

    public function asDelivered(): self
    {
        $clone = clone $this;
        $clone->status = OrderStatus::DELIVERED;

        return $clone;
    }

    public function asReturned(): self
    {
        $clone = clone $this;
        $clone->status = OrderStatus::RETURNED;

        return $clone;
    }

    public function asCancelled(): self
    {
        $clone = clone $this;
        $clone->status = OrderStatus::CANCELLED;

        return $clone;
    }

    public function build(): MarketplaceOrder
    {
        return new MarketplaceOrder(
            companyId: $this->companyId,
            listingId: $this->listingId,
            marketplace: $this->marketplace,
            externalOrderId: $this->externalOrderId,
            orderDate: $this->orderDate,
            quantity: $this->quantity,
            status: $this->status,
            rawDocumentId: $this->rawDocumentId,
            rawData: $this->rawData,
        );
    }
}
