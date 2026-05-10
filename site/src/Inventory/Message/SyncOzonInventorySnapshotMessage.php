<?php

declare(strict_types=1);

namespace App\Inventory\Message;

final readonly class SyncOzonInventorySnapshotMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
        public string $snapshotSessionId,
        public string $triggerType,
    ) {
    }
}
