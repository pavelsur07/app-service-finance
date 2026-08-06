<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class WbGeneratedRowsConflictException extends \LogicException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly int $linkedRows = 0,
        private readonly int $processedRows = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getLinkedRows(): int
    {
        return $this->linkedRows;
    }

    public function getProcessedRows(): int
    {
        return $this->processedRows;
    }
}
