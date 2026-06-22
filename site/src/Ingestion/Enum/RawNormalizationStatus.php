<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum RawNormalizationStatus: string
{
    case PENDING = 'pending';
    case SKIPPED = 'skipped';
    case DONE = 'done';
    case FAILED = 'failed';
}
