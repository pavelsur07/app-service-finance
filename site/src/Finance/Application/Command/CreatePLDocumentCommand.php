<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

use App\Finance\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Finance\Enum\PLDocumentSource;
use App\Finance\Enum\PLDocumentStream;

final readonly class CreatePLDocumentCommand
{
    /**
     * @param list<CreatePLDocumentOperationCommand> $operations
     */
    public function __construct(
        public string $companyId,
        public \DateTimeImmutable $date,
        public DocumentType $type,
        public DocumentStatus $status,
        public ?string $number = null,
        public ?string $description = null,
        public ?string $counterpartyId = null,
        public ?string $projectDirectionId = null,
        public array $operations = [],
        public ?PLDocumentSource $source = null,      // ← НОВОЕ
        public ?PLDocumentStream $stream = null,      // ← НОВОЕ
    ) {
    }
}
