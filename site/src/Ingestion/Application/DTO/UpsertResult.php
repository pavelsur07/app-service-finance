<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use Webmozart\Assert\Assert;

final readonly class UpsertResult
{
    public function __construct(
        public string $transactionId,
        public ?\DateTimeImmutable $oldOccurredAt,
        public \DateTimeImmutable $newOccurredAt,
        public bool $periodChanged,
        public bool $affectsFinancialReport = true,
    ) {
        Assert::uuid($this->transactionId);
    }
}
