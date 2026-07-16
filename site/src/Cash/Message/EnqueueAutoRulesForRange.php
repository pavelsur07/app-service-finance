<?php

namespace App\Cash\Message;

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
        public ?string $correlationId = null,
    ) {
    }

    public function __unserialize(array $data): void
    {
        $this->companyId = $data['companyId'];
        $this->from = $data['from'] ?? null;
        $this->to = $data['to'] ?? null;
        $this->moneyAccountIds = $data['moneyAccountIds'] ?? null;
        $this->correlationId = $data['correlationId'] ?? null;
    }
}
