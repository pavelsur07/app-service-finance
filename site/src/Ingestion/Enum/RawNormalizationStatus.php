<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum RawNormalizationStatus: string
{
    case PENDING = 'pending';
    case DONE = 'done';
    case FAILED = 'failed';
}
