<?php

namespace App\Deals\Service\Request;

use App\Deals\Enum\DealAdjustmentType;

final class AddDealAdjustmentRequest
{
    public function __construct(
        public readonly \DateTimeImmutable $recognizedAt,
        public readonly string $amount,
        public readonly DealAdjustmentType $type,
        public readonly ?string $comment = null,
    ) {
    }
}
