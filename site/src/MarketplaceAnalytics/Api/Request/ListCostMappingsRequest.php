<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Request;

use Symfony\Component\HttpFoundation\Request;

final readonly class ListCostMappingsRequest
{
    public function __construct(
        public ?string $marketplace,
        public int $page,
        public int $perPage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            marketplace: $request->query->get('marketplace'),
            page: max(1, $request->query->getInt('page', 1)),
            perPage: max(1, $request->query->getInt('perPage', 50)),
        );
    }
}
