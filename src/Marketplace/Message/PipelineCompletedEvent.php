<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

final readonly class PipelineCompletedEvent
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $marketplace,
        public readonly string $status,
        public readonly ?string $failedStep,
        public readonly int $salesCount,
        public readonly int $returnsCount,
        public readonly int $costsCount,
    ) {}
}
