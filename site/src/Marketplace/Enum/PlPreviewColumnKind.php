<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum PlPreviewColumnKind: string
{
    case TAG = 'tag';
    case COMMON = 'common';
    case TOTAL = 'total';
}
