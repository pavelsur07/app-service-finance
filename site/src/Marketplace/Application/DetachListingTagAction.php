<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\DTO\ListingTagPayload;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;

final class DetachListingTagAction
{
    public function __construct(
        private readonly ListingTagAssignmentRepository $assignments,
    ) {
    }

    /**
     * @return int сколько связей реально снято
     */
    public function __invoke(string $companyId, ListingTagPayload $payload): int
    {
        return $this->assignments->detach($companyId, $payload->listingIds, (string) $payload->tagId);
    }
}
