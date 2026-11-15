<?php

declare(strict_types=1);

namespace App\MoySklad\Exception;

final class ListingMappingException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return match ($this->reason) {
            'not_found' => 404, 'stale_mapping', 'idempotency_conflict' => 409, default => 422,
        };
    }
}
