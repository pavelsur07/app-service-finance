<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\DTO\AdvertisingCostDTO;
use App\Marketplace\DTO\OrderDTO;
use App\Marketplace\Repository\MarketplaceAdvertisingCostRepositoryInterface;
use App\Marketplace\Repository\MarketplaceOrderRepositoryInterface;

final readonly class MarketplaceFacade
{
    public function __construct(
        private MarketplaceAdvertisingCostRepositoryInterface $advertisingCostRepository,
        private MarketplaceOrderRepositoryInterface $orderRepository,
    ) {}

    /**
     * @return AdvertisingCostDTO[]
     */
    public function getAdvertisingCostsForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        $results = $this->advertisingCostRepository->findByListingAndDate(
            $companyId,
            $listingId,
            $date,
        );

        return array_map(AdvertisingCostDTO::fromEntity(...), $results);
    }

    /**
     * @return OrderDTO[]
     */
    public function getOrdersForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        $results = $this->orderRepository->findByListingAndDate(
            $companyId,
            $listingId,
            $date,
        );

        return array_map(OrderDTO::fromEntity(...), $results);
    }
}
