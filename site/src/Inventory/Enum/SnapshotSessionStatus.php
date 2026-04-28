<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

enum SnapshotSessionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
