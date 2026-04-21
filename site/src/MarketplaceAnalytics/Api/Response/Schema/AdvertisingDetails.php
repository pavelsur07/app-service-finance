<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response\Schema;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdvertisingDetails',
    description: 'Рекламные метрики (CPC + прочие расходы)',
    required: ['cpc', 'other'],
    properties: [
        new OA\Property(property: 'cpc', ref: new Model(type: AdvertisingCpcDetails::class)),
        new OA\Property(property: 'other', ref: new Model(type: AdvertisingOtherDetails::class)),
    ]
)]
final class AdvertisingDetails
{
}
