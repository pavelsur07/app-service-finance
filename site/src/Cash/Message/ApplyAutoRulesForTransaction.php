<?php

declare(strict_types=1);

namespace App\Cash\Message;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;

final readonly class ApplyAutoRulesForTransaction
{
    public function __construct(
        public string $transactionId,
        public string $companyId,
        public \DateTimeImmutable $createdAt,
        public ?string $correlationId = null,
        public CashTransactionAutoRuleApplyMode $mode = CashTransactionAutoRuleApplyMode::SAFE,
        public ?string $initiatedByUserId = null,
    ) {
    }

    public function __unserialize(array $data): void
    {
        $this->transactionId = $data['transactionId'];
        $this->companyId = $data['companyId'];
        $this->createdAt = $data['createdAt'];
        $this->correlationId = $data['correlationId'] ?? null;
        $this->mode = $data['mode'] ?? CashTransactionAutoRuleApplyMode::SAFE;
        $this->initiatedByUserId = $data['initiatedByUserId'] ?? null;
    }
}
