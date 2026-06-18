<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class SyncJobNotFoundException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'sync_job_not_found';
    }
}
