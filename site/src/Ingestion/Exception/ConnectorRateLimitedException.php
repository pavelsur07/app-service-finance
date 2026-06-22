<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class ConnectorRateLimitedException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $retryAfterSeconds,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function retryAfterSeconds(): int
    {
        return max(1, $this->retryAfterSeconds);
    }
}
