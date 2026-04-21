<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdvertisingOtherDetails',
    description: 'Прочие рекламные расходы',
    properties: [
        new OA\Property(property: 'spend', type: 'string', example: '0.00'),
        new OA\Property(
            property: 'details',
            type: 'array',
            items: new OA\Items(type: 'object', additionalProperties: true),
            description: 'Детализация (структура элементов зависит от источника)'
        ),
    ]
)]
final class AdvertisingOtherDetails
{
}
