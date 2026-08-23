<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Recalculates current-month data after listing cost price changes.
 * The explicit period keeps retries deterministic across month boundaries.
 */
final readonly class RecalculateListingCostPriceMessage
{
    /**
     * @param list<string> $listingIds
     */
    public function __construct(
        public string $companyId,
        public string $marketplace,
        public array $listingIds,
        public string $dateFrom,
        public string $dateTo,
        public string $actorUserId,
    ) {
    }
}
