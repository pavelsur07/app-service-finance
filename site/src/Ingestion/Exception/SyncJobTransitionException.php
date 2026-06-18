<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class SyncJobTransitionException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'sync_job_invalid_transition';
    }
}
