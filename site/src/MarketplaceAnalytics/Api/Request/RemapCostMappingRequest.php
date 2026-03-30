<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class RemapCostMappingRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: [
            'logistics_to',
            'logistics_back',
            'storage',
            'advertising_cpc',
            'advertising_other',
            'advertising_external',
            'commission',
            'other',
        ])]
        public readonly string $unitEconomyCostType = '',
    ) {}
}
