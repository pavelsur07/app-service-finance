<?php

namespace App\Cash\Message;

final readonly class ApplyAutoRulesForTransaction
{
    public function __construct(
        public string $transactionId,
        public string $companyId,
        public \DateTimeImmutable $createdAt,
        public ?string $correlationId = null,
    ) {
    }

    public function __unserialize(array $data): void
    {
        $this->transactionId = $data['transactionId'];
        $this->companyId = $data['companyId'];
        $this->createdAt = $data['createdAt'];
        $this->correlationId = $data['correlationId'] ?? null;
    }
}
