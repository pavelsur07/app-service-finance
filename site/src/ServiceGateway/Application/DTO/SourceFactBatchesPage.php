<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class SourceFactBatchesPage
{
    /**
     * @param list<SourceFactBatch> $batches
     */
    public function __construct(
        public array $batches,
        public ?string $nextCursor,
    ) {
    }
}
