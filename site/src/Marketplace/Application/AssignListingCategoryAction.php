<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\DTO\ListingCategoryAssignResult;
use App\Marketplace\Application\DTO\ListingsFilter;
use App\Marketplace\Enum\ListingCategorySource;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Marketplace\Exception\ListingFilterTooBroadException;
use App\Marketplace\Infrastructure\Query\ListingCategoryAssignmentQuery;
use App\Marketplace\Infrastructure\Query\MarketplaceListingsQuery;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Вручную назначает категорию набору листингов или снимает её (`categoryId = null`).
 * Набор — явный список id или фильтр реестра (ровно одно из двух). Повтор даёт
 * то же состояние и `changed = 0`.
 */
final class AssignListingCategoryAction
{
    public const DEFAULT_MAX_FILTER_MATCH = 5000;
    private const ASSIGN_CHUNK = 500;

    public function __construct(
        private readonly MarketplaceListingCategoryRepository $categories,
        private readonly ListingCategoryAssignmentQuery $assignments,
        private readonly MarketplaceListingsQuery $listingsQuery,
        private readonly EntityManagerInterface $em,
        private readonly int $maxFilterMatch = self::DEFAULT_MAX_FILTER_MATCH,
    ) {
    }

    /**
     * @param list<string> $listingIds
     */
    public function __invoke(string $companyId, ?string $categoryId, array $listingIds = [], ?ListingsFilter $filter = null): ListingCategoryAssignResult
    {
        if (([] === $listingIds) === (null === $filter)) {
            throw new \InvalidArgumentException('Pass exactly one of listingIds or filter.');
        }

        if (null !== $categoryId && null === $this->categories->findById($companyId, $categoryId)) {
            throw new ListingCategoryNotFoundException($categoryId);
        }

        if (null !== $filter) {
            $listingIds = $this->listingsQuery->matchingIds($companyId, $filter, $this->maxFilterMatch + 1);
            if (count($listingIds) > $this->maxFilterMatch) {
                throw new ListingFilterTooBroadException($this->maxFilterMatch);
            }
            $matched = count($listingIds);
        } else {
            $listingIds = array_values(array_unique($listingIds));
            $matched = $this->assignments->countCompanyListings($companyId, $listingIds);
        }

        // Порции одного назначения — одной транзакцией: без частично размеченного набора.
        $changed = $this->em->wrapInTransaction(function () use ($companyId, $categoryId, $listingIds): int {
            $changed = 0;
            foreach (array_chunk($listingIds, self::ASSIGN_CHUNK) as $chunk) {
                $changed += $this->assignments->assign($companyId, $chunk, $categoryId, ListingCategorySource::Manual);
            }

            return $changed;
        });

        return new ListingCategoryAssignResult($matched, $changed);
    }
}
