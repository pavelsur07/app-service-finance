<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;

/**
 * Вход для создания или изменения автоправила ДДС.
 *
 * null означает «не менять». Проект и ЦФО через этот вход не задаются — они
 * остаются за UI, чтобы не тянуть сюда проверку допустимости пары проект/ЦФО.
 *
 * @phpstan-type Conditions list<AutoRuleConditionInput>
 */
final readonly class AutoRuleInput
{
    /**
     * @param list<AutoRuleConditionInput>|null $conditions null — не менять, список — заменить целиком
     */
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?CashTransactionAutoRuleAction $action = null,
        public ?CashTransactionAutoRuleOperationType $operationType = null,
        public ?string $cashflowCategoryId = null,
        public ?string $counterpartyId = null,
        public ?int $priority = null,
        public ?bool $isActive = null,
        public ?array $conditions = null,
    ) {
    }
}
