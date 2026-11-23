<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;

final readonly class CashRulePhraseTarget
{
    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['counterparty_id'], (string) $row['counterparty_name'], CashTransactionAutoRuleOperationType::from((string) $row['direction']),
            (string) $row['category_id'], (string) $row['category_name'], $row['task_id'] ?? null, $row['status'] ?? null, $row['phrase'] ?? null,
        );
    }

    private function __construct(
        public string $counterpartyId,
        public string $counterpartyName,
        public CashTransactionAutoRuleOperationType $direction,
        public string $categoryId,
        public string $categoryName,
        public ?string $taskId,
        public ?string $status,
        public ?string $phrase,
    ) {
    }
}
