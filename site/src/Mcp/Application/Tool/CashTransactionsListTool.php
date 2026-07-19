<?php

declare(strict_types=1);

namespace App\Mcp\Application\Tool;

use App\Cash\Facade\CashFacade;
use App\Mcp\Application\McpToolInterface;

final class CashTransactionsListTool implements McpToolInterface
{
    use JsonToolOutput;

    public function __construct(
        private readonly CashFacade $cashFacade,
    ) {
    }

    public function name(): string
    {
        return 'cash_transactions_list';
    }

    public function description(): string
    {
        return 'Список транзакций ДДС компании с фильтрами и постраничной выдачей. '
            .'Применяй, когда нужны конкретные операции: приход и расход по счетам, датам, статьям, контрагентам.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dateFrom' => ['type' => 'string', 'description' => 'Дата с, включительно, формат YYYY-MM-DD'],
                'dateTo' => ['type' => 'string', 'description' => 'Дата по, включительно, формат YYYY-MM-DD'],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['INFLOW', 'OUTFLOW'],
                    'description' => 'INFLOW — поступление, OUTFLOW — списание',
                ],
                'accountId' => ['type' => 'string', 'description' => 'UUID денежного счёта'],
                'categoryId' => ['type' => 'string', 'description' => 'UUID статьи ДДС'],
                'counterpartyId' => ['type' => 'string', 'description' => 'UUID контрагента'],
                'amountMin' => ['type' => 'string', 'description' => 'Минимальная сумма, например 1000.00'],
                'amountMax' => ['type' => 'string', 'description' => 'Максимальная сумма'],
                'q' => ['type' => 'string', 'description' => 'Подстрока в назначении платежа'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Страница, с 1'],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => CashFacade::MAX_PER_PAGE,
                    'description' => 'Размер страницы, по умолчанию 50, максимум '.CashFacade::MAX_PER_PAGE,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(string $companyId, array $arguments): string
    {
        $filters = [];
        foreach (['dateFrom', 'dateTo', 'accountId', 'categoryId', 'counterpartyId', 'direction', 'amountMin', 'amountMax', 'q'] as $key) {
            $value = $arguments[$key] ?? null;
            $filters[$key] = \is_string($value) && '' !== $value ? $value : null;
        }

        return $this->json($this->cashFacade->listTransactions(
            $companyId,
            $filters,
            (int) ($arguments['page'] ?? 1),
            (int) ($arguments['limit'] ?? 50),
        ));
    }
}
