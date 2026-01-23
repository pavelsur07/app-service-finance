<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service\Dto;

final readonly class WbCommissionerAggregationResult
{
    /**
     * @param array<string, mixed> $errors
     */
    public function __construct(
        public bool $success,
        public array $errors = [],
    ) {
    }
}
