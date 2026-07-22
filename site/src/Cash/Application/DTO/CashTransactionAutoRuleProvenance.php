<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashTransactionAutoRuleProvenance
{
    /** @param array<string, bool> $autoAssignedFields */
    public function __construct(private array $autoAssignedFields)
    {
    }

    public function isAutoAssigned(string $field): bool
    {
        return true === ($this->autoAssignedFields[$field] ?? false);
    }
}
