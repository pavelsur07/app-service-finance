<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

enum SnapshotTriggerType: string
{
    case ScheduledNight = 'scheduled_night';
    case ScheduledDay = 'scheduled_day';
    case Manual = 'manual';
    case Retry = 'retry';
}
