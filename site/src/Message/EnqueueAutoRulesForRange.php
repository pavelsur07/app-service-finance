<?php

namespace App\Message;

final readonly class EnqueueAutoRulesForRange
{
    /**
     * @param list<string>|null $moneyAccountIds
     */
    public function __construct(
        public string $companyId,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?array $moneyAccountIds = null,
    ) {
    }
}
