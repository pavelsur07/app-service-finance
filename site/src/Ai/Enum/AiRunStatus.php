<?php

declare(strict_types=1);

namespace App\Ai\Enum;

enum AiRunStatus: string
{
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed';

    public function isFinished(): bool
    {
        return match ($this) {
            self::SUCCESS, self::FAILED => true,
            self::PENDING => false,
        };
    }
}
