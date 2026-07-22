<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use App\Mcp\Exception\McpToolNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Обработчик одного MCP-сообщения (JSON-RPC 2.0, построчный stdio-фрейминг).
 *
 * Транспорт живёт в McpServeCommand — здесь только протокол, чтобы его можно было
 * тестировать без запуска процесса.
 */
final class McpServer
{
    public const SERVER_NAME = 'vashfindir-cash';
    public const SERVER_VERSION = '0.1.0';

    private const DEFAULT_PROTOCOL_VERSION = '2025-06-18';
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const PARSE_ERROR = -32700;
    private const INVALID_REQUEST = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS = -32602;
    private const INTERNAL_ERROR = -32603;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null строка ответа или null, если ответ не нужен (уведомление)
     */
    public function handleLine(string $line, string $companyId, bool $allowWrite): ?string
    {
        try {
            $message = json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return $this->encode($this->error(null, self::PARSE_ERROR, $exception->getMessage()));
        }

        if (!\is_array($message) || !\is_string($message['method'] ?? null)) {
            return $this->encode($this->error(null, self::INVALID_REQUEST, 'Ожидался объект JSON-RPC с полем method.'));
        }

        // Уведомления (без id) ответа не требуют — в MVP это только notifications/initialized.
        $id = $message['id'] ?? null;
        if (null === $id) {
            return null;
        }

        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        $response = match ($message['method']) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, ['tools' => $this->listTools($allowWrite)]),
            'tools/call' => $this->callTool($id, $params, $companyId, $allowWrite),
            default => $this->error($id, self::METHOD_NOT_FOUND, sprintf('Метод "%s" не поддерживается.', $message['method'])),
        };

        return $this->encode($response);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => \in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
                ? $requested
                : self::DEFAULT_PROTOCOL_VERSION,
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listTools(bool $allowWrite): array
    {
        return array_map(
            static fn (McpToolInterface $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ],
            $this->tools->visible($allowWrite),
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params, string $companyId, bool $allowWrite): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            return $this->error($id, self::INVALID_PARAMS, 'Не указано имя инструмента.');
        }

        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $tool = $this->tools->get($name, $allowWrite);
        } catch (McpToolNotFoundException $exception) {
            return $this->error($id, self::INVALID_PARAMS, $exception->getMessage());
        }

        $startedAt = microtime(true);

        try {
            $text = $tool->call($companyId, $arguments);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            // Ожидаемый отказ: отдаём текстом, чтобы модель могла исправить аргументы сама.
            $this->logger->warning('MCP tool rejected call', [
                'tool' => $name,
                'companyId' => $companyId,
                'reason' => $exception->getMessage(),
            ]);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $exception->getMessage()]],
                'isError' => true,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('MCP tool failed', [
                'tool' => $name,
                'companyId' => $companyId,
                'exception' => $exception,
            ]);

            return $this->error($id, self::INTERNAL_ERROR, 'Внутренняя ошибка при выполнении инструмента.');
        }

        $this->logger->info('MCP tool call', [
            'tool' => $name,
            'companyId' => $companyId,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $text]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function encode(array $response): string
    {
        return json_encode($response, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
