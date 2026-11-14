<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class AiEvalLatest
{
    public function __construct(
        public bool $enabled,
        public ?string $configurationFingerprint,
        public ?AiEvalRun $run,
    ) {
    }
}
