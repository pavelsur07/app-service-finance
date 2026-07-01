<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

use Psr\Log\LoggerInterface;

/**
 * Декоратор {@see ObjectStorageInterface}: инструментирует все операции хранилища —
 * таймит, логирует `warning` на медленных (> slowMs) и `error` на сбоях со структурным
 * контекстом. Реросит исходное исключение. Прозрачен для вызывающего кода.
 *
 * error → GlitchTip (сбой хранилища = инцидент). warning (медленно) — только в лог.
 */
final readonly class LoggingObjectStorage implements ObjectStorageInterface
{
    public function __construct(
        private ObjectStorageInterface $inner,
        private LoggerInterface $logger,
        private int $slowMs = 1000,
    ) {
    }

    public function write(string $path, string $contents): StoredObject
    {
        return $this->measured('write', $path, fn (): StoredObject => $this->inner->write($path, $contents));
    }

    public function read(string $path): string
    {
        return $this->measured('read', $path, fn (): string => $this->inner->read($path));
    }

    public function readStream(string $path)
    {
        return $this->measured('readStream', $path, fn () => $this->inner->readStream($path));
    }

    public function exists(string $path): bool
    {
        return $this->measured('exists', $path, fn (): bool => $this->inner->exists($path));
    }

    public function delete(string $path): void
    {
        $this->measured('delete', $path, fn () => $this->inner->delete($path));
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function measured(string $operation, string $path, callable $callback): mixed
    {
        $startedAt = microtime(true);

        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            $this->logger->error('Object storage operation failed', [
                'operation' => $operation,
                'path' => $path,
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $durationMs = $this->elapsedMs($startedAt);
        if ($durationMs >= $this->slowMs) {
            $this->logger->warning('Object storage operation slow', [
                'operation' => $operation,
                'path' => $path,
                'duration_ms' => $durationMs,
                'threshold_ms' => $this->slowMs,
            ]);
        }

        return $result;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
