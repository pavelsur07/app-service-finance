<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class StaleTransactionUpdateException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'transaction_stale_update';
    }
}
