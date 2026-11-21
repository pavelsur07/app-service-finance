<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use App\Cash\Facade\CashFacade;
use App\Mcp\Application\McpCashCursor;
use App\Mcp\Application\McpCashTools;
use App\Mcp\Infrastructure\UserMcpServer;
use App\Mcp\Security\McpPrincipal;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Mcp\Server\Stateless\RequestMeta;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Opis\JsonSchema\Validator;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class UserMcpServerTest extends IntegrationTestCase
{
    public function testNegotiationAndScopeBoundCatalogAndErrors(): void
    {
        $records = new TestHandler();
        $server = new UserMcpServer(self::getContainer()->get(McpCashTools::class), new ArrayAdapter(), new Logger('mcp', [$records]), new LockFactory(new InMemoryStore()));
        $principal = new McpPrincipal(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), ['accounts.read']);
        $handshake = $this->message($server, $principal, [
            'method' => 'initialize',
            'id' => 1,
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'cash-v1-test', 'version' => '1.0.0']],
        ]);
        self::assertSame(200, $handshake->getStatusCode());
        $session = $handshake->headers->get('Mcp-Session-Id');
        self::assertNotNull($session);
        $result = self::decode($handshake);
        self::assertSame('2025-11-25', $result['result']['protocolVersion']);
        self::assertSame(202, $this->message($server, $principal, ['method' => 'notifications/initialized'], $session)->getStatusCode());

        $list = $this->message($server, $principal, ['method' => 'tools/list', 'id' => 2], $session);
        self::assertSame(200, $list->getStatusCode());
        self::assertSame(['list_reference_items'], array_column(self::decode($list)['result']['tools'], 'name'));
        $forbidden = $this->message($server, $principal, [
            'method' => 'tools/call',
            'id' => 3,
            'params' => ['name' => 'list_reference_items', 'arguments' => ['kind' => 'cashflow_categories']],
        ], $session);
        self::assertSame(403, $forbidden->getStatusCode());
        $invalid = $this->message($server, $principal, [
            'method' => 'tools/call',
            'id' => 4,
            'params' => ['name' => 'list_reference_items', 'arguments' => ['kind' => 'money_accounts', 'limit' => 0]],
        ], $session);
        self::assertTrue(self::decode($invalid)['result']['isError']);
        self::assertSame(['insufficient_scope', 'invalid_input'], array_column(array_column($records->getRecords(), 'context'), 'result'));
        foreach ($records->getRecords() as $record) {
            self::assertSame(['callId', 'connectionId', 'result', 'durationMs'], array_keys($record->context));
            self::assertSame($principal->connectionId, $record->context['connectionId']);
        }
        $foreign = new McpPrincipal($principal->companyId, $principal->userId, Uuid::uuid7()->toString(), ['accounts.read']);
        self::assertSame(404, $this->message($server, $foreign, ['method' => 'tools/list', 'id' => 5], $session)->getStatusCode());
    }

    public function testModernDiscoveryIsStatelessAndStreamingSubscriptionsAreNotAvailable(): void
    {
        $server = new UserMcpServer(self::getContainer()->get(McpCashTools::class), new ArrayAdapter(), new NullLogger(), new LockFactory(new InMemoryStore()));
        $principal = new McpPrincipal(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), ['cash_transactions.read']);
        $params = ['_meta' => [
            RequestMeta::PROTOCOL_VERSION => '2026-07-28',
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
        ]];

        $discover = $this->message($server, $principal, ['method' => 'server/discover', 'id' => 1, 'params' => $params], version: '2026-07-28');
        self::assertSame(200, $discover->getStatusCode());
        self::assertFalse($discover->headers->has('Mcp-Session-Id'));
        $list = $this->message($server, $principal, ['method' => 'tools/list', 'id' => 2, 'params' => $params], version: '2026-07-28');
        self::assertSame(200, $list->getStatusCode());
        self::assertSame(['list_cash_transactions'], array_column(self::decode($list)['result']['tools'], 'name'));
        self::assertSame(404, $this->message($server, $principal, ['method' => 'subscriptions/listen', 'id' => 3, 'params' => $params], version: '2026-07-28')->getStatusCode());

        $oversize = Request::create('http://localhost/mcp', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat('x', 65537));
        self::assertSame(413, $server->handle($oversize, $principal)->getStatusCode());
        $form = Request::create('http://localhost/mcp', 'POST', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'tool=read');
        self::assertSame(415, $server->handle($form, $principal)->getStatusCode());
    }

    public function testUnexpectedFailureIsLoggedWithoutExceptionMessageOrArguments(): void
    {
        // @phpstan-ignore method.unresolvableReturnType (PHPUnit bootstrap enables BypassFinals.)
        $cash = $this->createMock(CashFacade::class);
        $cash->expects(self::once())->method('listExternalAccounts')->willThrowException(new \RuntimeException('private error detail'));
        $tools = new McpCashTools($cash, new Validator(), new McpCashCursor('test-session-secret'));
        $records = new TestHandler();
        $server = new UserMcpServer($tools, new ArrayAdapter(), new Logger('mcp', [$records]), new LockFactory(new InMemoryStore()));
        $principal = new McpPrincipal(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), ['accounts.read']);
        $meta = ['_meta' => [RequestMeta::PROTOCOL_VERSION => '2026-07-28', RequestMeta::CLIENT_CAPABILITIES => new \stdClass()]];
        $response = $this->message($server, $principal, [
            'method' => 'tools/call',
            'id' => 1,
            'params' => $meta + ['name' => 'list_reference_items', 'arguments' => ['kind' => 'money_accounts', 'search' => 'private input']],
        ], version: '2026-07-28');
        self::assertSame(-32603, self::decode($response)['error']['code']);
        self::assertStringNotContainsString('private error detail', (string) $response->getContent());
        $errors = array_values(array_filter($records->getRecords(), static fn (LogRecord $record): bool => 'MCP tool execution failed' === $record->message));
        self::assertCount(1, $errors);
        self::assertSame('ERROR', $errors[0]->level->getName());
        self::assertSame(\RuntimeException::class, $errors[0]->context['exceptionClass']);
        self::assertSame($principal->connectionId, $errors[0]->context['connectionId']);
        $audit = array_values(array_filter($records->getRecords(), static fn (LogRecord $record): bool => 'MCP tool call' === $record->message));
        self::assertCount(1, $audit);
        self::assertSame($errors[0]->context['callId'], $audit[0]->context['callId']);
        self::assertSame('internal_error', $audit[0]->context['result']);
        $encoded = json_encode($records->getRecords(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private error detail', $encoded);
        self::assertStringNotContainsString('private input', $encoded);
    }

    /** @param array<string, mixed> $message */
    private function message(UserMcpServer $server, McpPrincipal $principal, array $message, ?string $session = null, string $version = '2025-11-25'): Response
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $session) {
            $headers['HTTP_MCP_SESSION_ID'] = $session;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = $version;
        }
        if ('2026-07-28' === $version) {
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = $version;
            $headers['HTTP_MCP_METHOD'] = (string) $message['method'];
            if ('tools/call' === $message['method']) {
                $headers['HTTP_MCP_NAME'] = (string) $message['params']['name'];
            }
        }
        $request = Request::create('http://localhost/mcp', 'POST', server: $headers, content: json_encode(['jsonrpc' => '2.0'] + $message, \JSON_THROW_ON_ERROR));

        return $server->handle($request, $principal);
    }

    /** @return array<string, mixed> */
    private static function decode(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
