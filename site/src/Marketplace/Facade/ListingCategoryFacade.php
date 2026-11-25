<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\Infrastructure\Query\ListingCategoryAssignmentQuery;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use Webmozart\Assert\Assert;

final readonly class ListingCategoryFacade implements ListingCategoryFacadeInterface
{
    public function __construct(
        private MarketplaceListingCategoryRepository $categories,
        private ListingCategoryAssignmentQuery $assignments,
    ) {
    }

    public function list(string $companyId): array
    {
        Assert::uuid($companyId);

        return $this->categories->listForCompany($companyId);
    }

    public function categoriesForListings(string $companyId, array $listingIds): array
    {
        Assert::uuid($companyId);

        return $this->assignments->categoriesForListings($companyId, $listingIds);
    }
}
