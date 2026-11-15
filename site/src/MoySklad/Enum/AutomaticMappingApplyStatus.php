<?php

declare(strict_types=1);

namespace App\MoySklad\Enum;

enum AutomaticMappingApplyStatus: string
{
    case ACCEPTED = 'accepted';
    case CONFLICT = 'conflict';
    case FAILED = 'failed';
    case NOT_PROCESSED = 'not_processed';
}
