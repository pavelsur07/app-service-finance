<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use App\Ingestion\DTO\RawBatch;

final readonly class PullResult
{
    public function __construct(
        public ?RawBatch $rawBatch,
        public ?string $nextCursorValue,
        public bool $hasMore,
        public bool $normalizeRawRecords = true,
        public ?int $continuationDelaySeconds = null,
    ) {
    }
}
