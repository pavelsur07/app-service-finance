<?php

declare(strict_types=1);

namespace App\Inventory\Exception;

final class WbInventoryRateLimitException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
