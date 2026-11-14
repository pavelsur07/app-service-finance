<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class AiEvalAccepted
{
    public function __construct(
        public string $runId,
        public string $status,
        public bool $duplicate,
        public string $configurationFingerprint,
    ) {
    }
}
