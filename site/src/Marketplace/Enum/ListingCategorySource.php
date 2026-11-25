<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Кто назначил категорию листингу. Ручное назначение сильнее правила:
 * синхронизация по правилам меняет только `Rule` и листинги без категории.
 */
enum ListingCategorySource: string
{
    case Manual = 'manual';
    case Rule = 'rule';
}
