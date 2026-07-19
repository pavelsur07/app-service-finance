<?php

declare(strict_types=1);

namespace App\Mcp\Application\Tool;

use App\Cash\Facade\CashFacade;
use App\Mcp\Application\McpToolInterface;

final class CashCategoriesTreeTool implements McpToolInterface
{
    use JsonToolOutput;

    public function __construct(
        private readonly CashFacade $cashFacade,
    ) {
    }

    public function name(): string
    {
        return 'cash_categories_tree';
    }

    public function description(): string
    {
        return 'Плоское дерево статей ДДС компании с уровнем вложенности и родителем. '
            .'Вызывай перед любой работой со статьями: id статей нужны фильтрам транзакций и автоправилам.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(string $companyId, array $arguments): string
    {
        return $this->json($this->cashFacade->listCashflowCategories($companyId));
    }
}
