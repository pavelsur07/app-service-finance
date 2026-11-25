<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\DTO\ListingCategoryDTO;

/**
 * Категории листингов для других модулей (MarketplaceEconomics — фильтр и
 * группировка «Экономики SKU»). Только чтение, в рамках компании.
 */
interface ListingCategoryFacadeInterface
{
    /**
     * @return list<ListingCategoryDTO> по имени
     */
    public function list(string $companyId): array;

    /**
     * @param list<string> $listingIds
     *
     * @return array<string, string> listingId → categoryId; без категории и чужие — не попадают
     */
    public function categoriesForListings(string $companyId, array $listingIds): array;
}
