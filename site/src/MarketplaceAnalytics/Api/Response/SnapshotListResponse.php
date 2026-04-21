<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

use App\Shared\OpenApi\Schema\PaginationMeta;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SnapshotListResponse',
    description: 'Пагинированный список снэпшотов маркетплейс-аналитики',
    required: ['data', 'meta'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SnapshotResponse::class))
        ),
        new OA\Property(property: 'meta', ref: new Model(type: PaginationMeta::class)),
    ]
)]
final class SnapshotListResponse
{
}
