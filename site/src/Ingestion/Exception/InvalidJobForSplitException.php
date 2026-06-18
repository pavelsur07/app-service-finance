<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class InvalidJobForSplitException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'sync_job_not_splittable';
    }
}
