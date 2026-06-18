<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class ActiveBackfillExistsException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'sync_backfill_already_running';
    }
}
