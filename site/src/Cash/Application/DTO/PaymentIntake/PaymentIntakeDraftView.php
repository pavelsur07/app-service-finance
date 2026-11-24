<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\PaymentIntake;

final readonly class PaymentIntakeDraftView
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public string $id,
        public string $companyId,
        public string $actorUserId,
        public string $moneyAccountId,
        public int $revision,
        public string $status,
        public array $items,
        public ?string $mcpExternalId = null,
        public ?\DateTimeImmutable $confirmedAt = null,
        public ?string $confirmedTransactionId = null,
        public ?string $mcpConnectionId = null,
        public ?\DateTimeImmutable $receivedAt = null,
    ) {
    }
}
