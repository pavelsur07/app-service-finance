<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service\Dto;

final readonly class WbCommissionerDimensionExtractResult
{
    public function __construct(
        public int $dimensionsTotal,
    ) {
    }
}
