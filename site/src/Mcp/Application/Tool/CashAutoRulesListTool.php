<?php

declare(strict_types=1);

namespace App\Mcp\Application\Tool;

use App\Cash\Facade\CashFacade;
use App\Mcp\Application\McpToolInterface;

final class CashAutoRulesListTool implements McpToolInterface
{
    use JsonToolOutput;

    public function __construct(
        private readonly CashFacade $cashFacade,
    ) {
    }

    public function name(): string
    {
        return 'cash_autorules_list';
    }

    public function description(): string
    {
        return 'Автоправила ДДС компании вместе с их условиями и целевой статьёй. '
            .'Вызывай перед изменением автоправила, чтобы взять его id и увидеть текущие условия.';
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
        return $this->json($this->cashFacade->listAutoRules($companyId));
    }
}
