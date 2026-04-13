<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Domain\Service\ListingSalesProviderInterface;

final readonly class ListingSalesProviderMarketplace implements ListingSalesProviderInterface
{
    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
    ) {
    }

    public function getSalesQuantitiesByListings(
        string $companyId,
        array $listingIds,
        \DateTimeImmutable $date,
    ): array {
        return $this->marketplaceFacade->getSalesQuantitiesForListings($companyId, $listingIds, $date);
    }

    /**
     * Находит все листинги (включая неактивные) с данным marketplaceSku на указанной площадке.
     * В WB nm_id — родительский артикул, общий для всех размеров одного товара.
     *
     * {@inheritdoc}
     */
    public function findListingsByParentSku(
        string $companyId,
        string $marketplace,
        string $parentSku,
    ): array {
        return $this->marketplaceFacade->findListingsByMarketplaceSku($companyId, $marketplace, $parentSku);
    }

    public function findListingsByParentSkus(
        string $companyId,
        string $marketplace,
        array $parentSkus,
    ): array {
        return $this->marketplaceFacade->findListingsByMarketplaceSkus($companyId, $marketplace, $parentSkus);
    }
}
