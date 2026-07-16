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

    public function __wakeup(): void
    {
        if (!(new \ReflectionProperty($this, 'correlationId'))->isInitialized($this)) {
            $this->correlationId = null;
        }
    }
}
