<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Application;

use App\Mcp\Application\McpServer;
use App\Mcp\Application\McpToolInterface;
use App\Mcp\Application\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class McpServerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public function testInitializeEchoesSupportedProtocolVersion(): void
    {
        $response = $this->handle(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']]);

        self::assertSame('2024-11-05', $response['result']['protocolVersion']);
        self::assertSame(McpServer::SERVER_NAME, $response['result']['serverInfo']['name']);
    }

    public function testInitializeFallsBackOnUnknownProtocolVersion(): void
    {
        $response = $this->handle(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);

        self::assertSame('2025-06-18', $response['result']['protocolVersion']);
    }

    public function testNotificationProducesNoResponse(): void
    {
        $server = $this->server();

        self::assertNull($server->handleLine(
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], \JSON_THROW_ON_ERROR),
            self::COMPANY_ID,
            false,
        ));
    }

    public function testReadOnlyModeHidesWritingTools(): void
    {
        $response = $this->handle(['id' => 2, 'method' => 'tools/list']);

        $names = array_column($response['result']['tools'], 'name');
        self::assertSame(['reader'], $names);
    }

    public function testWriteModeExposesWritingTools(): void
    {
        $response = $this->handle(['id' => 2, 'method' => 'tools/list'], allowWrite: true);

        $names = array_column($response['result']['tools'], 'name');
        self::assertEqualsCanonicalizing(['reader', 'writer', 'failing'], $names);
    }

    public function testCallReturnsToolTextAndCompanyId(): void
    {
        $response = $this->handle([
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'reader', 'arguments' => ['limit' => 10]],
        ]);

        self::assertSame(
            'reader:'.self::COMPANY_ID.':{"limit":10}',
            $response['result']['content'][0]['text'],
        );
        self::assertArrayNotHasKey('isError', $response['result']);
    }

    public function testWritingToolIsRejectedWithoutAllowWrite(): void
    {
        $response = $this->handle([
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'writer'],
        ]);

        self::assertSame(-32602, $response['error']['code']);
        self::assertStringContainsString('--allow-write', $response['error']['message']);
    }

    public function testDomainErrorIsReturnedAsToolTextNotProtocolError(): void
    {
        $response = $this->handle([
            'id' => 5,
            'method' => 'tools/call',
            'params' => ['name' => 'failing'],
        ], allowWrite: true);

        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
        self::assertSame('Статья не найдена.', $response['result']['content'][0]['text']);
    }

    public function testBrokenJsonReturnsParseError(): void
    {
        $server = $this->server();
        $raw = $server->handleLine('{не json', self::COMPANY_ID, false);

        self::assertNotNull($raw);
        self::assertSame(-32700, json_decode($raw, true, 512, \JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $response = $this->handle(['id' => 6, 'method' => 'resources/list']);

        self::assertSame(-32601, $response['error']['code']);
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function handle(array $message, bool $allowWrite = false): array
    {
        $raw = $this->server()->handleLine(
            json_encode($message + ['jsonrpc' => '2.0'], \JSON_THROW_ON_ERROR),
            self::COMPANY_ID,
            $allowWrite,
        );

        self::assertNotNull($raw);

        return json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    }

    private function server(): McpServer
    {
        $reader = $this->tool('reader', false, static fn (string $companyId, array $args): string => sprintf(
            'reader:%s:%s',
            $companyId,
            json_encode($args, \JSON_THROW_ON_ERROR),
        ));
        $writer = $this->tool('writer', true, static fn (): string => 'ok');
        $failing = $this->tool('failing', true, static function (): string {
            throw new \DomainException('Статья не найдена.');
        });

        return new McpServer(new ToolRegistry([$reader, $writer, $failing]), new NullLogger());
    }

    private function tool(string $name, bool $writing, \Closure $call): McpToolInterface
    {
        return new class($name, $writing, $call) implements McpToolInterface {
            public function __construct(
                private readonly string $name,
                private readonly bool $writing,
                private readonly \Closure $call,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'test tool';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass()];
            }

            public function isWriting(): bool
            {
                return $this->writing;
            }

            public function call(string $companyId, array $arguments): string
            {
                return ($this->call)($companyId, $arguments);
            }
        };
    }
}
