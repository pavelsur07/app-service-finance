<?php

declare(strict_types=1);

namespace App\Balance\Application\DTO;

final readonly class BalanceBookStatus
{
    public function __construct(
        public ?string $currency,
        public ?string $startDate,
        public bool $initialized,
    ) {
    }
}
