<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SnapshotListResponse',
    description: 'Пагинированный список снэпшотов маркетплейс-аналитики',
    required: ['data', 'meta'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/SnapshotResponse')
        ),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
    ]
)]
final class SnapshotListResponse
{
}
