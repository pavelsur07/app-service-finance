<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

final class JobAlreadySplitException extends \DomainException
{
    public function getErrorCode(): string
    {
        return 'sync_job_already_split';
    }
}
