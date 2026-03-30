<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Request;

use Symfony\Component\HttpFoundation\Request;

final readonly class ListCostMappingsRequest
{
    public function __construct(
        public ?string $marketplace,
        public ?bool $isSystem,
        public int $page,
        public int $perPage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $isSystemRaw = $request->query->get('isSystem');
        $isSystem = match ($isSystemRaw) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };

        return new self(
            marketplace: $request->query->get('marketplace'),
            isSystem: $isSystem,
            page: max(1, $request->query->getInt('page', 1)),
            perPage: max(1, $request->query->getInt('perPage', 50)),
        );
    }
}
