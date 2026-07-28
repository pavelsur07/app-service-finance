<?php

declare(strict_types=1);

namespace App\Mcp\Application\Tool;

use App\Company\Facade\CompanyFacade;
use App\Mcp\Application\McpToolInterface;

final class CompanyFindByNameTool implements McpToolInterface
{
    use JsonToolOutput;

    public function __construct(
        private readonly CompanyFacade $companyFacade,
    ) {
    }

    public function name(): string
    {
        return 'company_find_by_name';
    }

    public function description(): string
    {
        return 'Глобально найти ID компании по полному названию без учёта регистра. '
            .'Используй, когда известное название нужно преобразовать в UUID компании.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Полное название компании',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(string $companyId, array $arguments): string
    {
        $name = $arguments['name'] ?? null;
        if (!\is_string($name)) {
            throw new \InvalidArgumentException('Укажите название компании.');
        }

        return $this->json(['id' => $this->companyFacade->resolveIdByName($name)]);
    }
}
