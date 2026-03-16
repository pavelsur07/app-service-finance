<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum JobStatus: string
{
    case RUNNING = 'running';
    case DONE    = 'done';
    case FAILED  = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::RUNNING => 'Выполняется',
            self::DONE    => 'Завершено',
            self::FAILED  => 'Ошибка',
        };
    }
}
