<?php

declare(strict_types=1);

namespace App\Shared\Enum;

enum AuditLogAction: string
{
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case SOFT_DELETE = 'SOFT_DELETE';
    case RESTORE = 'RESTORE';
}
