<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Запускает переобработку затрат одного RawDocument через process()-путь
 * (DELETE + reinsert). Диспатчится из Admin UI.
 */
final readonly class ReprocessCostsMessage
{
    public function __construct(
        public string $companyId,
        public string $rawDocumentId,
    ) {
    }
}
