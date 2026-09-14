<?php

declare(strict_types=1);

namespace App\Balance\Exception;

class BalanceLedgerException extends \DomainException
{
    public function __construct(string $message, public readonly int $statusCode = 422)
    {
        parent::__construct($message);
    }
}
