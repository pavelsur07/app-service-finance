<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdvertisingDetails',
    description: 'Рекламные метрики (CPC + прочие расходы)',
    required: ['cpc', 'other'],
    properties: [
        new OA\Property(property: 'cpc', ref: '#/components/schemas/AdvertisingCpcDetails'),
        new OA\Property(property: 'other', ref: '#/components/schemas/AdvertisingOtherDetails'),
    ]
)]
final class AdvertisingDetails
{
}
