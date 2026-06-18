<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use App\Ingestion\Enum\SyncJobStatus;

final readonly class SyncJobProgressView
{
    public function __construct(
        public string $jobId,
        public SyncJobStatus $status,
        public int $progressDone,
        public int $progressTotal,
        public int $attempts,
        public ?string $lastError,
    ) {
    }
}
