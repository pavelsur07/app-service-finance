<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

final readonly class ProcessRawDocumentStepMessage
{
    public function __construct(
        public string $rawDocumentId,
        public string $step,
        public string $companyId,
    ) {
    }
}
