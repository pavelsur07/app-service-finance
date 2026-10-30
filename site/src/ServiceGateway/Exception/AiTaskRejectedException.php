<?php

declare(strict_types=1);

namespace App\ServiceGateway\Exception;

/**
 * Сервис отверг постановку задачи (4xx: неизвестный task_type, конфликт ключа повтора,
 * неверный запрос). Повтор того же запроса не поможет.
 */
final class AiTaskRejectedException extends \RuntimeException
{
    public static function status(int $status, string $code): self
    {
        return new self(sprintf('ai-service rejected the task: HTTP %d %s', $status, $code));
    }
}
