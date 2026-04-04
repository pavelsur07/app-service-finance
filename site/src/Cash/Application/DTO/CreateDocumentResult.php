<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CreateDocumentResult
{
    public function __construct(
        public bool $needsConfirmation,
        public ?string $documentId,
        public bool $hasViolation,
        public string $warningMessage,
    ) {
    }
}
