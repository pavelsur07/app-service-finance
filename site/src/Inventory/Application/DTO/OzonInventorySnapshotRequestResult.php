<?php

declare(strict_types=1);

namespace App\Inventory\Application\DTO;

final readonly class OzonInventorySnapshotRequestResult
{
    /**
     * @param list<string> $messages
     */
    public function __construct(
        public int $queuedCount,
        public int $skippedCount,
        public bool $hasConnections,
        public bool $hasActiveSession,
        public array $messages = [],
    ) {
    }
}
