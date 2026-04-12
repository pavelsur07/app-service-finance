<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Domain\Service\ListingSalesProviderInterface;

final readonly class ListingSalesProviderMarketplace implements ListingSalesProviderInterface
{
    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
    ) {}

    public function getSalesQuantityForDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): int {
        $sales = $this->marketplaceFacade->getSalesForListingAndDate($companyId, $listingId, $date);

        $total = 0;
        foreach ($sales as $sale) {
            $total += $sale->quantity;
        }

        return $total;
    }

    /**
     * Находит все активные листинги с данным marketplaceSku (nm_id) на указанной площадке.
     * В WB nm_id — родительский артикул, общий для всех размеров одного товара.
     *
     * @return list<array{id: string, parentSku: string}>
     */
    public function findListingsByParentSku(
        string $companyId,
        string $marketplace,
        string $parentSku,
    ): array {
        $allListings = $this->marketplaceFacade->getActiveListings($companyId, $marketplace);

        $result = [];
        foreach ($allListings as $listing) {
            if ($listing->marketplaceSku === $parentSku) {
                $result[] = ['id' => $listing->id, 'parentSku' => $listing->marketplaceSku];
            }
        }

        return $result;
    }
}
