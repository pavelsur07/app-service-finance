<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CostBreakdown',
    description: 'Разбивка себестоимости по статьям (decimal as string)',
    required: [
        'other',
        'storage',
        'acquiring',
        'penalties',
        'acceptance',
        'commission',
        'logistics_to',
        'logistics_back',
        'advertising_cpc',
        'advertising_other',
        'advertising_external',
    ],
    properties: [
        new OA\Property(property: 'other', type: 'string', example: '0.00'),
        new OA\Property(property: 'storage', type: 'string', example: '0.00'),
        new OA\Property(property: 'acquiring', type: 'string', example: '0.00'),
        new OA\Property(property: 'penalties', type: 'string', example: '0.00'),
        new OA\Property(property: 'acceptance', type: 'string', example: '0.00'),
        new OA\Property(property: 'commission', type: 'string', example: '0.00'),
        new OA\Property(property: 'logistics_to', type: 'string', example: '0.00'),
        new OA\Property(property: 'logistics_back', type: 'string', example: '0.00'),
        new OA\Property(property: 'advertising_cpc', type: 'string', example: '0.00'),
        new OA\Property(property: 'advertising_other', type: 'string', example: '0.00'),
        new OA\Property(property: 'advertising_external', type: 'string', example: '0.00'),
    ]
)]
final class CostBreakdown
{
}
