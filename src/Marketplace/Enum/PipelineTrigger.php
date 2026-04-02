<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum PipelineTrigger: string
{
    case AUTO   = 'auto';
    case MANUAL = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::AUTO   => 'Автоматически',
            self::MANUAL => 'Вручную',
        };
    }
}
