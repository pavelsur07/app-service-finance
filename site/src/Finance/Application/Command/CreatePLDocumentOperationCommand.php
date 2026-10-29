<?php

declare(strict_types=1);

namespace App\Finance\Application\Command;

/**
 * Операция создаваемого документа ОПиУ. Контрагента у операции нет: он один на документ
 * (CreatePLDocumentCommand::$counterpartyId) и переходит в операции из шапки.
 */
final readonly class CreatePLDocumentOperationCommand
{
    public function __construct(
        public string $amount,
        public ?string $categoryId = null,
        public ?string $projectDirectionId = null,
        public ?string $responsibilityCenterId = null,
        public ?string $comment = null,
    ) {
    }
}
