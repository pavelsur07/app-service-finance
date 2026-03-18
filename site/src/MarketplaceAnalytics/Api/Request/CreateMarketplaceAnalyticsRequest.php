<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateMarketplaceAnalyticsRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $title,

        // public readonly ?string $description = null,
    ) {}
}
