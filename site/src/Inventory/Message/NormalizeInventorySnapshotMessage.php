<?php

declare(strict_types=1);

namespace App\Inventory\Message;

final readonly class NormalizeInventorySnapshotMessage
{
    public function __construct(
        public string $companyId,
        public string $snapshotSessionId,
        public string $source,
    ) {
    }
}
