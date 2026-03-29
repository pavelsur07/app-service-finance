<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

use App\Marketplace\Entity\MarketplaceOrder;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OrderStatus;

final readonly class OrderDTO
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $listingId,
        public MarketplaceType $marketplace,
        public string $externalOrderId,
        public \DateTimeImmutable $orderDate,
        public int $quantity,
        public OrderStatus $status,
    ) {}

    public static function fromEntity(MarketplaceOrder $entity): self
    {
        return new self(
            id: $entity->getId(),
            companyId: $entity->getCompanyId(),
            listingId: $entity->getListingId(),
            marketplace: $entity->getMarketplace(),
            externalOrderId: $entity->getExternalOrderId(),
            orderDate: $entity->getOrderDate(),
            quantity: $entity->getQuantity(),
            status: $entity->getStatus(),
        );
    }
}
