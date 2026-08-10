<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CreateCashTransferCommand
{
    public function __construct(
        public string $companyId,
        public string $sourceAccountId,
        public string $targetAccountId,
        public string $sourceAmount,
        public string $targetAmount,
        public \DateTimeImmutable $occurredAt,
        public string $idempotencyKey,
        public ?string $description = null,
    ) {
    }
}
