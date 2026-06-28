<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Psr\Log\LoggerInterface;

/**
 * Единая точка входа для логирования бизнес-событий и метрик.
 */
class AppLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
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
     * Предупреждение: нештатная, но не фатальная ситуация (пишется в файл/консоль).
     * Не отправляется в Sentry автоматически.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
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
     * Фиксация деградации производительности.
     *
     * Пишет ТОЛЬКО в локальный лог как warning. В GlitchTip намеренно не уходит:
     * по конвенции туда попадает только ERROR (медленное выполнение — не инцидент).
     * Перформанс отслеживается отдельно (Sentry tracing выключен осознанно).
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

        $this->logger->warning($message);
    }
}
