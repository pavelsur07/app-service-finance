<?php

namespace App\Deals\Exception;

final class ValidationFailed extends \RuntimeException
{
    /**
     * @param array<string, mixed> $errors
     */
    public function __construct(
        string $message = 'Validation failed.',
        public readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
