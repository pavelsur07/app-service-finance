<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Request;

use Symfony\Component\HttpFoundation\Request;

final readonly class ListSnapshotsRequest
{
    public function __construct(
        public ?string $marketplace,
        public int $page,
        public int $perPage,
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $listingId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            marketplace: $request->query->get('marketplace'),
            page: $request->query->getInt('page', 1),
            perPage: $request->query->getInt('perPage', 20),
            dateFrom: $request->query->get('dateFrom'),
            dateTo: $request->query->get('dateTo'),
            listingId: $request->query->get('listingId'),
        );
    }
}
