<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class MoneyMismatchException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'money_currency_mismatch';
    }
}
