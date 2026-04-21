<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'CreateMarketplaceAnalyticsRequest',
    description: 'Тело запроса на создание маркетплейс-аналитики',
    required: ['title'],
    properties: [
        new OA\Property(
            property: 'title',
            type: 'string',
            minLength: 1,
            example: 'Аналитика за Q1 2026',
        ),
    ]
)]
final class CreateMarketplaceAnalyticsRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $title,

        // public readonly ?string $description = null,
    ) {}
}
