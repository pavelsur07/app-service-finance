<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashAutoRuleQualityCounts
{
    public function __construct(
        public int $assigned = 0,
        public int $corrected = 0,
        public int $conflicts = 0,
    ) {
    }
}
