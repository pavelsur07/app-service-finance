<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Service;

use App\Ingestion\Enum\SyncJobStatus;
use App\Ingestion\Exception\SyncJobTransitionException;

final class SyncJobTransitionPolicy
{
    public static function assertCanTransition(SyncJobStatus $from, SyncJobStatus $to): void
    {
        if (!$from->canTransitionTo($to)) {
            throw new SyncJobTransitionException(sprintf('Invalid sync job transition from "%s" to "%s".', $from->value, $to->value));
        }
    }
}
