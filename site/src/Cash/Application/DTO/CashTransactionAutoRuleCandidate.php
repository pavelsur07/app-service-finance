<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;

final readonly class CashTransactionAutoRuleCandidate
{
    /** @param list<array{id: string, date: string}> $examples */
    public function __construct(
        public CashTransactionAutoRuleConditionField $field,
        public string $value,
        public string $valueLabel,
        public CashTransactionAutoRuleOperationType $operationType,
        public string $categoryId,
        public string $categoryName,
        public int $sampleCount,
        public int $distinctDateCount,
        public array $examples = [],
        public ?string $phrase = null,
    ) {
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->field, $this->value, $this->operationType, $this->categoryId], \JSON_THROW_ON_ERROR));
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
