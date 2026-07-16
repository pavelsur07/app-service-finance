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

    public function __wakeup(): void
    {
        if (!isset($this->correlationId)) {
            $this->correlationId = null;
        }
    }
}
