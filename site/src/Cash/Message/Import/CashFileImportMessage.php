<?php

namespace App\Cash\Message\Import;

final class CashFileImportMessage
{
    public function __construct(private readonly string $jobId)
    {
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }
}
