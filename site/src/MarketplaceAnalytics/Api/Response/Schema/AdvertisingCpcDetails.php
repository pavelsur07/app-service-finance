<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdvertisingCpcDetails',
    description: 'Метрики CPC-рекламы',
    properties: [
        new OA\Property(property: 'cr', type: 'number', format: 'float', example: 0, description: 'Conversion rate'),
        new OA\Property(property: 'cpc', type: 'string', example: '0.00'),
        new OA\Property(property: 'cpm', type: 'string', example: '0.00'),
        new OA\Property(property: 'cpo', type: 'string', example: '0.00'),
        new OA\Property(property: 'ctr', type: 'number', format: 'float', example: 0),
        new OA\Property(property: 'acos', type: 'string', example: '0.00'),
        new OA\Property(property: 'spend', type: 'string', example: '0.00'),
        new OA\Property(property: 'clicks', type: 'integer', example: 0),
        new OA\Property(property: 'orders', type: 'integer', example: 0),
        new OA\Property(property: 'revenue', type: 'string', example: '0.00'),
        new OA\Property(property: 'impressions', type: 'integer', example: 0),
    ]
)]
final class AdvertisingCpcDetails
{
}
