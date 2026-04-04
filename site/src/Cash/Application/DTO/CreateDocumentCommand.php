<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CreateDocumentCommand
{
    public function __construct(
        public string $cashTransactionId,
        public \DateTimeImmutable $occurredAt,
        public string $amount,
        public ?string $counterpartyId,
        public ?string $projectDirectionId,
        public ?string $plCategoryId,
        public bool $createdWithViolation,
    ) {
    }
}
