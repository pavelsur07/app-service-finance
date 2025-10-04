<?php

namespace App\Message;

use DateTimeImmutable;

final readonly class ApplyAutoRulesForTransaction
{
    public function __construct(
        public string $transactionId,
        public string $companyId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
