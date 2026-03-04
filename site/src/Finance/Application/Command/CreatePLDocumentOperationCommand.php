<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

final readonly class CreatePLDocumentOperationCommand
{
    public function __construct(
        public string $amount,
        public ?string $categoryId = null,
        public ?string $counterpartyId = null,
        public ?string $projectDirectionId = null,
        public ?string $comment = null,
    ) {
    }
}
