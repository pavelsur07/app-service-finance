<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CreateCashTransferResult
{
    public function __construct(
        public string $transferId,
        public string $sourceTransactionId,
        public string $targetTransactionId,
        public bool $created,
        public bool $duplicate,
    ) {
    }
}
