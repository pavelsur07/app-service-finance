<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum DefaultCostMappingPreviewStatus: string
{
    case WILL_CREATE = 'will_create';
    case WILL_FILL_EMPTY = 'will_fill_empty';
    case SKIPPED_EXISTING = 'skipped_existing';
    case SKIPPED_DISABLED = 'skipped_disabled';
    case MISSING_COST_CATEGORY = 'missing_cost_category';
    case MISSING_PL_CATEGORY = 'missing_pl_category';
    case INVALID_TARGET_CATEGORY = 'invalid_target_category';
}
