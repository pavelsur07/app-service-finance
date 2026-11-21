<?php

declare(strict_types=1);

namespace App\Mcp\Controller;

use App\Company\Security\PublicAccess;
use App\Mcp\Infrastructure\UserMcpServer;
use App\Mcp\Security\McpAccess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[PublicAccess] // OAuth access is checked against the current MCP grant.
final readonly class UserMcpController
{
    public function __construct(
        private McpAccess $access,
        private UserMcpServer $server,
        #[Autowire(service: 'limiter.user_mcp')]
        private RateLimiterFactory $requests,
        #[Autowire(service: 'limiter.user_mcp_failed_auth')]
        private RateLimiterFactory $failedAuth,
    ) {
    }

    #[Route('/mcp', name: 'user_mcp', methods: ['GET', 'POST', 'DELETE'])]
    public function __invoke(Request $request): Response
    {
        $ipLimit = $this->failedAuth->create($request->getClientIp() ?? 'unknown');
        $previousFailures = $ipLimit->consume(0);
        if (!$previousFailures->isAccepted() || 0 === $previousFailures->getRemainingTokens()) {
            return $this->rateLimited($previousFailures->getRetryAfter());
        }
        try {
            $principal = $this->access->authenticate($request);
        } catch (HttpExceptionInterface $exception) {
            if (Response::HTTP_UNAUTHORIZED === $exception->getStatusCode()) {
                $ipLimit->consume();
            }

            return $this->privateResponse(new JsonResponse(
                ['error' => 'mcp_access_denied'],
                $exception->getStatusCode(),
                $exception->getHeaders(),
            ));
        }
        foreach (['connection-'.$principal->connectionId, 'user-'.$principal->userId] as $key) {
            $limit = $this->requests->create($key)->consume();
            if (!$limit->isAccepted()) {
                return $this->rateLimited($limit->getRetryAfter());
            }
        }
        $response = $this->server->handle($request, $principal);
        if (Response::HTTP_FORBIDDEN === $response->getStatusCode() && !$response->headers->has('WWW-Authenticate')) {
            $response->headers->set('WWW-Authenticate', $this->access->challenge('insufficient_scope'));
        }

        return $this->privateResponse($response);
    }

    private function rateLimited(\DateTimeImmutable $retryAt): Response
    {
        return $this->privateResponse(new JsonResponse(
            ['error' => 'rate_limit_exceeded'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) max(1, $retryAt->getTimestamp() - time())],
        ));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setVary('Authorization', false);

        return $response;
    }
}
