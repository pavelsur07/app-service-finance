<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;

final readonly class AutoRuleConditionInput
{
    public function __construct(
        public CashTransactionAutoRuleConditionField $field,
        public CashTransactionAutoRuleConditionOperator $operator,
        public ?string $value = null,
        public ?string $valueTo = null,
        public ?string $counterpartyId = null,
        public ?string $moneyAccountId = null,
    ) {
    }
}
