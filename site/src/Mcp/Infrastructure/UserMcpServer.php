<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure;

use App\Mcp\Application\McpCashTools;
use App\Mcp\Security\McpPrincipal;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SymfonyLockSessionLock;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\ReadsBoundedBody;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;

final readonly class UserMcpServer
{
    use ReadsBoundedBody;

    private const int MAX_REQUEST_BYTES = 65536;
    private const int MAX_RESPONSE_BYTES = 1048576;

    public function __construct(
        private McpCashTools $tools,
        #[Autowire(service: 'cache.mcp_sessions')]
        private CacheItemPoolInterface $sessions,
        #[Autowire(service: 'monolog.logger.mcp')]
        private LoggerInterface $logger,
        private LockFactory $locks,
    ) {
    }

    public function handle(Request $request, McpPrincipal $principal): Response
    {
        $factory = new HttpFactory();
        $headers = array_map(static fn (array $values): array => array_values(array_filter($values, 'is_string')), $request->headers->all());
        $psrRequest = new ServerRequest($request->getMethod(), $request->getUri(), $headers, $request->getContent(true));
        if ($request->isMethod('POST')) {
            if ('json' !== $request->getContentTypeFormat()) {
                return new JsonResponse(Error::forInvalidRequest('Запрос MCP должен содержать JSON.'), Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
            $body = $this->readBoundedBody($psrRequest->getBody(), self::MAX_REQUEST_BYTES);
            if (null === $body) {
                return new JsonResponse(Error::forInvalidRequest('Запрос превышает допустимый размер.'), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }
            $envelope = json_decode($body);
            if (\is_array($envelope)) {
                return new JsonResponse(Error::forInvalidRequest('Пакетные запросы недоступны.'), Response::HTTP_BAD_REQUEST);
            }
            if ($envelope instanceof \stdClass && 'subscriptions/listen' === ($envelope->method ?? null)) {
                $id = $envelope->id ?? null;

                return new JsonResponse(Error::forMethodNotFound('Подписки недоступны.', \is_int($id) || \is_string($id) ? $id : null), Response::HTTP_NOT_FOUND);
            }
            $psrRequest = $psrRequest->withBody($factory->createStream($body));
        }
        $sessionPrefix = 'mcp-'.substr(hash('sha256', $principal->companyId.'/'.$principal->userId.'/'.$principal->connectionId), 0, 22).'-';
        $handler = new McpCashRequestHandler($this->tools, $principal, $this->logger);
        $sdkLogger = new McpSdkErrorLogger($this->logger, $principal->connectionId);
        $server = Server::builder()
            ->setServerInfo('VashFinDir', '1.0.0')
            ->setLogger($sdkLogger)
            ->setCapabilities(new ServerCapabilities(tools: true, resources: false, prompts: false))
            ->addRequestHandler($handler)
            ->setSession(new Psr16SessionStore(new Psr16Cache($this->sessions), $sessionPrefix, 900), gcProbability: 0)
            ->setSessionLock(new SymfonyLockSessionLock($this->locks, timeout: 3, prefix: $sessionPrefix))
            ->build();

        // The resource controller already validated the canonical origin against server configuration.
        $response = $server->run(new StreamableHttpTransport(
            $psrRequest,
            $factory,
            $factory,
            $sdkLogger,
            middleware: [new DnsRebindingProtectionMiddleware([$request->getHost()], responseFactory: $factory, streamFactory: $factory)],
            maxBodyBytes: self::MAX_REQUEST_BYTES,
        ));
        if (null !== $denial = $handler->denial()) {
            return new JsonResponse(['error' => 'insufficient_scope'], Response::HTTP_FORBIDDEN, $denial->getHeaders());
        }
        if (($response->getBody()->getSize() ?? 0) > self::MAX_RESPONSE_BYTES) {
            return new JsonResponse(Error::forInvalidRequest('Уменьшите размер страницы.'), Response::HTTP_BAD_REQUEST);
        }

        return (new HttpFoundationFactory())->createResponse($response);
    }
}
