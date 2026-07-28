<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * MCP-инструмент. Реализации регистрируются автоматически по тегу.
 *
 * companyId приходит из аргумента процесса, а не из параметров инструмента,
 * поэтому финансовые инструменты не могут обратиться к данным чужой компании.
 * Справочный company_find_by_name — явно задокументированное исключение:
 * он глобально возвращает только ID компании.
 */
#[AutoconfigureTag('app.mcp_tool')]
interface McpToolInterface
{
    /** Имя инструмента в протоколе, snake_case. */
    public function name(): string;

    /** Одно предложение «когда применять» — это часть промпта, а не документация. */
    public function description(): string;

    /**
     * JSON Schema аргументов.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /** Инструмент меняет данные — доступен только с --allow-write. */
    public function isWriting(): bool;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string текст ответа для модели
     */
    public function call(string $companyId, array $arguments): string;
}
