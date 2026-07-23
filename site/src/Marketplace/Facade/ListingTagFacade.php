<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\DTO\ListingTagDTO;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;

/**
 * Единственная точка входа к тегам листингов для других модулей
 * (MarketplaceAnalytics — фильтр и колонка тегов в юнит-экономике).
 */
final readonly class ListingTagFacade
{
    public function __construct(
        private MarketplaceListingTagRepository $tagRepository,
        private ListingTagAssignmentRepository $assignments,
    ) {
    }

    /**
     * @return list<ListingTagDTO>
     */
    public function list(string $companyId): array
    {
        return $this->tagRepository->listForCompany($companyId);
    }

    /**
     * Листинги компании, помеченные заданными тегами.
     *
     * @param list<string> $tagIds
     *
     * @return list<string> listingId; пустой массив, если тегов не передали
     */
    public function listingIdsByTags(string $companyId, array $tagIds, bool $matchAll = false): array
    {
        return $this->assignments->listingIdsByTags($companyId, $tagIds, $matchAll);
    }

    /**
     * @param list<string> $listingIds
     *
     * @return array<string, list<ListingTagDTO>> ключ — listingId
     */
    public function tagsForListings(string $companyId, array $listingIds): array
    {
        return $this->assignments->tagsForListings($companyId, $listingIds);
    }
}
