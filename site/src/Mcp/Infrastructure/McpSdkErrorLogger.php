<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class McpSdkErrorLogger extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $connectionId,
    ) {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!\in_array($level, [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY], true)) {
            return;
        }

        // SDK messages and context may include MCP request or response data.
        $exception = $context['exception'] ?? null;
        $safeContext = ['connectionId' => $this->connectionId];
        if ($exception instanceof \Throwable) {
            $safeContext['exceptionClass'] = $exception::class;
        }

        $this->logger->log($level, 'MCP protocol failure', $safeContext);
    }
}
