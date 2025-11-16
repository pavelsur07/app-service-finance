<?php

declare(strict_types=1);

namespace App\Ai\Enum;

enum AiSuggestionSeverity: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Низкая',
            self::MEDIUM => 'Средняя',
            self::HIGH => 'Высокая',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::LOW => 'badge bg-green',
            self::MEDIUM => 'badge bg-yellow',
            self::HIGH => 'badge bg-red',
        };
    }
}
