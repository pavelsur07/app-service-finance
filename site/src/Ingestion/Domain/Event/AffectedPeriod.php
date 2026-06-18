<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Event;

final readonly class AffectedPeriod
{
    public function __construct(
        public string $shopRef,
        public ?\DateTimeImmutable $oldOccurredAt,
        public \DateTimeImmutable $newOccurredAt,
    ) {
    }
}
