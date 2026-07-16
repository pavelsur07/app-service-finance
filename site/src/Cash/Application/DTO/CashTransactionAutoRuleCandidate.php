<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;

final readonly class CashTransactionAutoRuleCandidate
{
    public function __construct(
        public CashTransactionAutoRuleConditionField $field,
        public string $value,
        public string $valueLabel,
        public CashTransactionAutoRuleOperationType $operationType,
        public string $categoryId,
        public string $categoryName,
        public int $sampleCount,
        public int $distinctDateCount,
    ) {
    }

    public function fieldLabel(): string
    {
        return match ($this->field) {
            CashTransactionAutoRuleConditionField::COUNTERPARTY => 'Контрагент',
            CashTransactionAutoRuleConditionField::MONEY_ACCOUNT => 'Счёт',
            CashTransactionAutoRuleConditionField::IMPORT_SOURCE => 'Источник импорта',
            CashTransactionAutoRuleConditionField::CURRENCY => 'Валюта',
            CashTransactionAutoRuleConditionField::DOCUMENT_TYPE => 'Тип документа',
            CashTransactionAutoRuleConditionField::IS_TRANSFER => 'Перевод между счетами',
            default => throw new \LogicException('Unsupported candidate condition field.'),
        };
    }

    public function operationTypeLabel(): string
    {
        return match ($this->operationType) {
            CashTransactionAutoRuleOperationType::INFLOW => 'Приток',
            CashTransactionAutoRuleOperationType::OUTFLOW => 'Отток',
            CashTransactionAutoRuleOperationType::ANY => 'Любое',
        };
    }
}
