<?php

declare(strict_types=1);

namespace App\MoySklad\Exception;

final class StoreBalanceException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 'store_not_found' === $this->reason ? 404 : 422;
    }
}
