<?php

namespace App\Deals\Service\Request;

final class RemoveDealChargeRequest
{
    public function __construct(
        public readonly string $chargeId,
    ) {
    }
}
