<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure;

use App\Mcp\Application\McpCashTools;
use App\Mcp\Application\McpToolException;
use App\Mcp\Security\McpPrincipal;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** @implements RequestHandlerInterface<CallToolResult|ListToolsResult> */
#[Exclude]
final class McpCashRequestHandler implements RequestHandlerInterface
{
    private ?AccessDeniedHttpException $denial = null;

    public function __construct(
        private readonly McpCashTools $tools,
        private readonly McpPrincipal $principal,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest || $request instanceof CallToolRequest;
    }

    /** @return Response<CallToolResult|ListToolsResult>|Error */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if ($request instanceof ListToolsRequest) {
            if (null !== $request->cursor) {
                return Error::forInvalidParams('Некорректный курсор каталога.', $request->getId());
            }

            return new Response($request->getId(), new ListToolsResult(array_map(
                static fn (array $definition): Tool => Tool::fromArray($definition),
                $this->tools->definitions($this->principal),
            )));
        }

        \assert($request instanceof CallToolRequest);
        $started = hrtime(true);
        $callId = Uuid::uuid7()->toString();
        $result = 'internal_error';

        try {
            $payload = $this->tools->call($this->principal, $request->name, $request->arguments);
            $content = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            $result = 'success';

            return new Response($request->getId(), new CallToolResult(
                [new TextContent($content)],
                structuredContent: $payload,
            ));
        } catch (McpToolException $exception) {
            $result = $exception->reason;
            $content = json_encode(['code' => $result, 'message' => $exception->getMessage()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

            return new Response($request->getId(), CallToolResult::error([new TextContent($content)]));
        } catch (AccessDeniedHttpException $exception) {
            $this->denial = $exception;
            $result = 'insufficient_scope';

            return Error::forInvalidParams('Инструмент недоступен.', $request->getId());
        } catch (\InvalidArgumentException) {
            $result = 'invalid_params';

            return Error::forInvalidParams('Неизвестный инструмент или неверные параметры.', $request->getId());
        } catch (\Throwable $exception) {
            $this->logger->error('MCP tool execution failed', [
                'callId' => $callId,
                'connectionId' => $this->principal->connectionId,
                'exceptionClass' => $exception::class,
            ]);

            return Error::forInternalError('Ошибка выполнения инструмента.', $request->getId());
        } finally {
            $this->logger->info('MCP tool call', [
                'callId' => $callId,
                'connectionId' => $this->principal->connectionId,
                'result' => $result,
                'durationMs' => intdiv(hrtime(true) - $started, 1000000),
            ]);
        }
    }

    public function denial(): ?AccessDeniedHttpException
    {
        return $this->denial;
    }
}
