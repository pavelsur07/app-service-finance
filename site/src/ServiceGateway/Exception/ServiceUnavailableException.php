<?php

declare(strict_types=1);

namespace App\ServiceGateway\Exception;

/**
 * Сервис не ответил или ответил не 200: транспорт, таймаут, 401, 5xx.
 * Ожидаемый сбой — фоновое обновление повторится по расписанию.
 */
final class ServiceUnavailableException extends \RuntimeException
{
    public static function transport(string $service, \Throwable $previous): self
    {
        return new self(sprintf('Service %s is unavailable: %s', $service, $previous::class), 0, $previous);
    }

    public static function status(string $service, int $status): self
    {
        return new self(sprintf('Service %s responded with HTTP %d', $service, $status));
    }

    public static function notConfigured(string $service): self
    {
        return new self(sprintf('Service %s is not configured: base URL or token is empty', $service));
    }
}
