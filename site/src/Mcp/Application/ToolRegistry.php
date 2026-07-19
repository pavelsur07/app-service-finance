<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use App\Mcp\Exception\McpToolNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ToolRegistry
{
    /** @var array<string, McpToolInterface> */
    private array $tools = [];

    /** @param iterable<McpToolInterface> $tools */
    public function __construct(
        #[AutowireIterator('app.mcp_tool')] iterable $tools,
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * @return list<McpToolInterface>
     */
    public function visible(bool $allowWrite): array
    {
        $visible = array_filter(
            $this->tools,
            static fn (McpToolInterface $tool): bool => $allowWrite || !$tool->isWriting(),
        );

        return array_values($visible);
    }

    public function get(string $name, bool $allowWrite): McpToolInterface
    {
        $tool = $this->tools[$name] ?? null;

        if (null === $tool) {
            throw new McpToolNotFoundException(sprintf('Инструмент "%s" не найден.', $name));
        }

        if ($tool->isWriting() && !$allowWrite) {
            throw new McpToolNotFoundException(sprintf('Инструмент "%s" изменяет данные и недоступен: сервер запущен без --allow-write.', $name));
        }

        return $tool;
    }
}
