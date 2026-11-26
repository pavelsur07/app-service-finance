<?php

declare(strict_types=1);

namespace App\OneC\Exception;

/**
 * Отказ API обмена: HTTP-статус и стабильный код из контракта v1.
 */
final class OneCApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $error, string $message)
    {
        parent::__construct($message);
    }
}
