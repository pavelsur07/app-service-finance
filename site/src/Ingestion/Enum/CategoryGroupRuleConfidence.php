<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

/**
 * Насколько правилу можно верить: `high` ставит группу статье из справочника
 * сразу, `medium` только подсказывает её в очереди разбора.
 */
enum CategoryGroupRuleConfidence: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';

    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'Высокая',
            self::MEDIUM => 'Средняя',
        };
    }
}
