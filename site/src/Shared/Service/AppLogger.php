<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Sentry\Severity;

/**
 * Единая точка входа для логирования бизнес-событий и метрик.
 */
class AppLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HubInterface $sentryHub
    ) {
    }

    /**
     * Обычный информационный лог (пишется только в файл/консоль)
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /**
     * Фиксация ошибок с отправкой в Sentry
     */
    public function error(string $message, \Throwable $exception = null, array $context = []): void
    {
        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        // Monolog сам перехватит ERROR и отправит в Sentry (благодаря нашим настройкам),
        // поэтому тут мы просто вызываем стандартный логгер.
        $this->logger->error($message, $context);
    }

    /**
     * Точечная отправка аномалий производительности (деградации) в Sentry
     */
    public function logSlowExecution(string $operationName, int $durationMs, int $thresholdMs = 0): void
    {
        $message = sprintf(
            'Медленное выполнение: [%s] заняло %d мс',
            $operationName,
            $durationMs
        );

        if ($thresholdMs > 0) {
            $message .= sprintf(' (лимит: %d мс)', $thresholdMs);
        }

        // 1. Пишем в локальный лог как Warning
        $this->logger->warning($message);

        // 2. Отправляем алерт в GlitchTip вручную
        $this->sentryHub->captureMessage($message, Severity::warning());
    }
}
