<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Enum;

enum RuleOrigin: string
{
    /** Из шаблона платформы: обновления шаблона приходят сами. */
    case PRESET = 'preset';
    /** Решение компании: шаблон его не перезаписывает. */
    case CUSTOM = 'custom';
}
