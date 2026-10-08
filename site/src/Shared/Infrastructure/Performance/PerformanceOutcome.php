<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Performance;

enum PerformanceOutcome: string
{
    case Ok = 'ok';
    case Error = 'error';
    /** Сбой обработчика, Messenger повторит сообщение. */
    case Retry = 'retry';
    /** Область замера закрыта без события завершения: следующее сообщение пришло раньше. */
    case Unknown = 'unknown';
}
