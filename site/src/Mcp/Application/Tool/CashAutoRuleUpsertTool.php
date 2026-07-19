<?php

declare(strict_types=1);

namespace App\Mcp\Application\Tool;

use App\Cash\Application\DTO\AutoRuleConditionInput;
use App\Cash\Application\DTO\AutoRuleInput;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Facade\CashFacade;
use App\Mcp\Application\McpToolInterface;

final class CashAutoRuleUpsertTool implements McpToolInterface
{
    use JsonToolOutput;
    use EnumArgument;

    public function __construct(
        private readonly CashFacade $cashFacade,
    ) {
    }

    public function name(): string
    {
        return 'cash_autorule_upsert';
    }

    public function description(): string
    {
        return 'Создать автоправило ДДС (без id) или изменить существующее (с id). '
            .'Автоправило проставляет статью ДДС транзакциям, подходящим под все его условия. '
            .'Переданный список conditions заменяет прежние условия целиком. '
            .'Проект и ЦФО этим инструментом не задаются. '
            .'Уже отключённое автоправило снова включить нельзя.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'UUID автоправила. Без него создаётся новое.'],
                'name' => ['type' => 'string', 'description' => 'Название. Обязательно при создании.'],
                'cashflowCategoryId' => [
                    'type' => 'string',
                    'description' => 'UUID статьи ДДС, которую правило проставит. Обязательно при создании.',
                ],
                'operationType' => [
                    'type' => 'string',
                    'enum' => ['INFLOW', 'OUTFLOW', 'ANY'],
                    'description' => 'К каким операциям применять. По умолчанию ANY.',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['FILL', 'UPDATE'],
                    'description' => 'FILL — заполнять только пустые поля. По умолчанию FILL.',
                ],
                'counterpartyId' => ['type' => 'string', 'description' => 'UUID контрагента, которого проставит правило'],
                'priority' => ['type' => 'integer', 'description' => 'Меньше — раньше. По умолчанию 100.'],
                'isActive' => ['type' => 'boolean', 'description' => 'false отключает правило безвозвратно'],
                'conditions' => [
                    'type' => 'array',
                    'description' => 'Условия, соединяются по И. При создании нужно хотя бы одно.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => [
                                'type' => 'string',
                                'enum' => array_column(CashTransactionAutoRuleConditionField::cases(), 'value'),
                                'description' => 'COUNTERPARTY требует counterpartyId, MONEY_ACCOUNT требует moneyAccountId, остальные — value',
                            ],
                            'operator' => [
                                'type' => 'string',
                                'enum' => array_column(CashTransactionAutoRuleConditionOperator::cases(), 'value'),
                                'description' => 'CONTAINS для текстовых полей, EQUAL для точных, BETWEEN для дат и сумм',
                            ],
                            'value' => ['type' => 'string', 'description' => 'Дата YYYY-MM-DD, сумма 1000.00, ИНН 10 или 12 цифр, валюта RUB, признак перевода true или false'],
                            'valueTo' => ['type' => 'string', 'description' => 'Верхняя граница для BETWEEN'],
                            'counterpartyId' => ['type' => 'string'],
                            'moneyAccountId' => ['type' => 'string'],
                        ],
                        'required' => ['field', 'operator'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function call(string $companyId, array $arguments): string
    {
        $id = $this->cashFacade->upsertAutoRule($companyId, new AutoRuleInput(
            id: $this->stringArg($arguments, 'id'),
            name: $this->stringArg($arguments, 'name'),
            action: $this->enumArg($arguments, 'action', CashTransactionAutoRuleAction::class),
            operationType: $this->enumArg($arguments, 'operationType', CashTransactionAutoRuleOperationType::class),
            cashflowCategoryId: $this->stringArg($arguments, 'cashflowCategoryId'),
            counterpartyId: $this->stringArg($arguments, 'counterpartyId'),
            priority: isset($arguments['priority']) ? (int) $arguments['priority'] : null,
            isActive: isset($arguments['isActive']) ? (bool) $arguments['isActive'] : null,
            conditions: $this->conditions($arguments),
        ));

        return $this->json(['id' => $id, 'saved' => true]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<AutoRuleConditionInput>|null
     */
    private function conditions(array $arguments): ?array
    {
        if (!isset($arguments['conditions']) || !\is_array($arguments['conditions'])) {
            return null;
        }

        $conditions = [];
        foreach ($arguments['conditions'] as $index => $raw) {
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException(sprintf('Условие %d должно быть объектом.', $index));
            }

            $field = $this->enumArg($raw, 'field', CashTransactionAutoRuleConditionField::class);
            $operator = $this->enumArg($raw, 'operator', CashTransactionAutoRuleConditionOperator::class);
            if (null === $field || null === $operator) {
                throw new \InvalidArgumentException(sprintf('В условии %d нужны field и operator.', $index));
            }

            $conditions[] = new AutoRuleConditionInput(
                field: $field,
                operator: $operator,
                value: $this->stringArg($raw, 'value'),
                valueTo: $this->stringArg($raw, 'valueTo'),
                counterpartyId: $this->stringArg($raw, 'counterpartyId'),
                moneyAccountId: $this->stringArg($raw, 'moneyAccountId'),
            );
        }

        return $conditions;
    }
}
