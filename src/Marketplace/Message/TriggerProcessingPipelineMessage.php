<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

final readonly class TriggerProcessingPipelineMessage
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $marketplace,
        public readonly string $triggeredBy,
    ) {}
}
