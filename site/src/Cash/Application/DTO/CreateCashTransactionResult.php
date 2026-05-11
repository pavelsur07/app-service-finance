<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CreateCashTransactionResult
{
    public function __construct(
        public string $transactionId,
        public bool $created,
        public bool $duplicate,
    ) {}
}
