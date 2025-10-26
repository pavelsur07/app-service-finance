<?php

namespace App\Message;

final readonly class ApplyAutoRulesForTransaction
{
    public function __construct(
        public string $transactionId,
        public string $companyId,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
